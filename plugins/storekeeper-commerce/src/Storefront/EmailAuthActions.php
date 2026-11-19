<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront;

use StoreKeeper\Commerce\Cart\CartService;
use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;
use StoreKeeper\Commerce\Core\Plugin;
use StoreKeeper\Commerce\Customers\EmailAuth;

/**
 * Email + password and "Continue with Google" sign-in for the storefront.
 *
 * These replace the phone + one-time-code forms. Like the rest of the shop they
 * are nonce-checked `admin-post.php` posts, so sign-in works without JavaScript
 * (D-028). The service owns the credentials and session; this class only moves
 * values between the request, the session cookie and the cart.
 */
final class EmailAuthActions
{
    private const GOOGLE_COOKIE = 'skf_goog';

    private const GOOGLE_KEY = 'storekeeper_google_state_';

    public static function register(): void
    {
        foreach ([
            'storekeeper_email_register' => 'registerAccount',
            'storekeeper_email_login' => 'login',
            'storekeeper_google_start' => 'googleStart',
            'storekeeper_google_callback' => 'googleCallback',
        ] as $action => $method) {
            add_action('admin_post_' . $action, [self::class, $method]);
            add_action('admin_post_nopriv_' . $action, [self::class, $method]);
        }
    }

    public static function registerAccount(): void
    {
        FormNonce::verify(
            'storekeeper_email_register',
            'This page had expired, so your account was not created. Please try again.',
            home_url('/register/')
        );

        $registration = Plugin::container()->get(StorefrontRegistration::class);

        try {
            $clean = $registration->validate($_POST);
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $result = self::emailAuth()->register(
                $clean,
                (string) ($_POST['password'] ?? ''),
                $phone === '' ? null : $phone,
                self::ip(),
                isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null
            );
        } catch (StoreKeeperException $error) {
            CartSession::flash($error->getMessage());
            self::redirect(home_url('/register/'));
        }

        self::finishSignIn($result, isSignup: true);
    }

    public static function login(): void
    {
        FormNonce::verify(
            'storekeeper_email_login',
            'This page had expired, so you were not signed in. Please try again.',
            home_url('/sign-in/')
        );

        try {
            $result = self::emailAuth()->login(
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                self::ip(),
                isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null
            );
        } catch (StoreKeeperException $error) {
            CartSession::flash($error->getMessage());
            self::redirect(home_url('/sign-in/'));
        }

        self::finishSignIn($result, isSignup: false);
    }

    public static function googleStart(): void
    {
        FormNonce::verify(
            'storekeeper_google_start',
            'This page had expired. Please try again.',
            home_url('/sign-in/')
        );

        $google = self::google();

        if (!$google->isConfigured()) {
            CartSession::flash('Google sign-in is not set up for this shop yet.');
            self::redirect(home_url('/sign-in/'));
        }

        $state = bin2hex(random_bytes(16));
        set_transient(self::GOOGLE_KEY . hash('sha256', $state), 1, 600);
        self::setGoogleCookie($state);

        self::redirect($google->authorizationUrl($state));
    }

    public static function googleCallback(): void
    {
        $state = isset($_GET['state']) ? (string) $_GET['state'] : '';
        $cookie = $_COOKIE[self::GOOGLE_COOKIE] ?? null;
        $valid = is_string($cookie)
            && hash_equals($cookie, $state)
            && preg_match('/^[a-f0-9]{32}$/', $state) === 1
            && get_transient(self::GOOGLE_KEY . hash('sha256', $state)) !== false;

        self::clearGoogleCookie($state);

        if (!$valid) {
            CartSession::flash('That Google sign-in had expired. Please try again.');
            self::redirect(home_url('/sign-in/'));
        }

        if (isset($_GET['error']) || !isset($_GET['code'])) {
            CartSession::flash('Google sign-in was cancelled.');
            self::redirect(home_url('/sign-in/'));
        }

        try {
            $google = self::google();
            $claims = $google->exchange((string) $_GET['code']);
            $result = $google->signIn(
                $claims,
                self::ip(),
                isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null
            );
        } catch (StoreKeeperException $error) {
            CartSession::flash($error->getMessage());
            self::redirect(home_url('/sign-in/'));
        }

        self::finishSignIn($result, isSignup: false);
    }

    /**
     * Establish the session, fold in the guest cart and send the shopper on.
     *
     * @param array{token:string, customer:array<string,mixed>} $result
     */
    private static function finishSignIn(array $result, bool $isSignup): void
    {
        CustomerSession::establish($result['token']);

        $cart = CartSession::cart(false);

        if ($cart !== null) {
            $cart = Plugin::container()->get(CartService::class)->claim($cart, (int) $result['customer']['id']);
        }

        $customer = $result['customer'];

        if ($isSignup && (string) ($customer['approval_status'] ?? 'approved') === 'pending') {
            CartSession::flash('Your account is being reviewed. You can shop at retail prices meanwhile.');
        } else {
            CartSession::flash(($isSignup ? 'Welcome, ' : 'Welcome back, ') . self::firstName((string) $customer['name']) . '.');
        }

        $hasItems = $cart !== null && Plugin::container()->get(CartService::class)->lineCount($cart) > 0;

        self::redirect($hasItems ? home_url('/checkout/') : home_url('/shop/'));
    }

    private static function setGoogleCookie(string $state): void
    {
        setcookie(self::GOOGLE_COOKIE, $state, [
            'expires' => time() + 600,
            'path' => defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
            'secure' => function_exists('is_ssl') && is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::GOOGLE_COOKIE] = $state;
    }

    private static function clearGoogleCookie(string $state): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $state) === 1) {
            delete_transient(self::GOOGLE_KEY . hash('sha256', $state));
        }

        setcookie(self::GOOGLE_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
            'secure' => function_exists('is_ssl') && is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::GOOGLE_COOKIE]);
    }

    private static function emailAuth(): EmailAuth
    {
        return Plugin::container()->get(EmailAuth::class);
    }

    private static function google(): GoogleAuth
    {
        return Plugin::container()->get(GoogleAuth::class);
    }

    private static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return $parts[0] ?? $name;
    }

    private static function ip(): ?string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        return filter_var($ip, FILTER_VALIDATE_IP) === false ? null : $ip;
    }

    private static function redirect(string $url): void
    {
        wp_safe_redirect($url);
        exit;
    }
}
