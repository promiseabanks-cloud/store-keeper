<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront;

use StoreKeeper\Commerce\Cart\CartService;
use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;
use StoreKeeper\Commerce\Core\Plugin;
use StoreKeeper\Commerce\Customers\StorefrontAuth;

/**
 * Sign-up and repeat-order forms (§11.1, §11.2).
 *
 * Like sign-in these are nonce-checked `admin-post.php` posts, so the shop keeps
 * working without JavaScript (D-028). The pending sign-up lives in a ten-minute
 * HTTP-only cookie, keyed to a transient, exactly like the sign-in challenge.
 */
final class AccountActions
{
    public const SIGNUP_COOKIE = 'skf_signup';

    private const SIGNUP_KEY = 'storekeeper_signup_';

    public static function register(): void
    {
        foreach ([
            'storekeeper_register_request' => 'registerRequest',
            'storekeeper_register_verify' => 'registerVerify',
            'storekeeper_reorder' => 'reorder',
        ] as $action => $method) {
            add_action('admin_post_' . $action, [self::class, $method]);
            add_action('admin_post_nopriv_' . $action, [self::class, $method]);
        }
    }

    public static function registerRequest(): void
    {
        FormNonce::verify(
            'storekeeper_register_request',
            'This page had expired, so no code was sent. Please try again.',
            home_url('/register/')
        );

        $registration = Plugin::container()->get(StorefrontRegistration::class);

        try {
            $clean = $registration->validate($_POST);
            $phone = Plugin::container()
                ->get(StorefrontAuth::class)
                ->requestRegistration((string) ($_POST['phone'] ?? ''), self::ip());
        } catch (StoreKeeperException $error) {
            CartSession::flash($error->getMessage());
            self::redirect(home_url('/register/'));
        }

        $challenge = bin2hex(random_bytes(16));
        set_transient(self::SIGNUP_KEY . hash('sha256', $challenge), ['phone' => $phone, 'data' => $clean], 600);
        self::setSignupCookie($challenge);

        CartSession::flash('We sent a 6-digit code to ' . AuthActions::mask($phone) . '.');

        self::redirect(home_url('/register/'));
    }

    public static function registerVerify(): void
    {
        FormNonce::verify(
            'storekeeper_register_verify',
            'This page had expired, so the code was not checked. Please try again.',
            home_url('/register/')
        );

        $pending = self::pendingSignup();

        if ($pending === null) {
            CartSession::flash('That sign-up attempt had expired. Please start again.');
            self::redirect(home_url('/register/'));
        }

        try {
            $auth = Plugin::container()->get(StorefrontAuth::class);
            $phone = $auth->verifyRegistration($pending['phone'], (string) ($_POST['code'] ?? ''));
            $customer = Plugin::container()
                ->get(StorefrontRegistration::class)
                ->register($pending['data'], $phone);
            $token = $auth->openSession(
                (int) $customer['id'],
                self::ip(),
                isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null
            );
        } catch (StoreKeeperException $error) {
            CartSession::flash($error->getMessage());
            self::redirect(home_url('/register/'));
        }

        self::clearSignup();
        CustomerSession::establish($token);

        // Items picked before signing up belong to this shopper now.
        $cart = CartSession::cart(false);

        if ($cart !== null) {
            $cart = Plugin::container()->get(CartService::class)->claim($cart, (int) $customer['id']);
        }

        CartSession::flash((string) $customer['approval_status'] === 'pending'
            ? 'Your account is being reviewed. You can shop at retail prices meanwhile.'
            : 'Welcome, ' . self::firstName((string) $customer['name']) . '.');

        self::redirect($cart !== null && Plugin::container()->get(CartService::class)->lineCount($cart) > 0
            ? home_url('/checkout/')
            : home_url('/shop/'));
    }

    public static function reorder(): void
    {
        FormNonce::verify(
            'storekeeper_reorder',
            'This page had expired, so the order was not repeated. Please try again.',
            home_url('/account/')
        );

        $customer = Storefront::customer();

        if ($customer === null) {
            CartSession::flash('Please sign in to repeat an order.');
            self::redirect(home_url('/sign-in/'));
        }

        try {
            $cart = CartSession::cart(true);
            $result = Plugin::container()
                ->get(StorefrontAccount::class)
                ->repeat($customer, (string) ($_POST['order'] ?? ''), $cart ?? []);
        } catch (StoreKeeperException $error) {
            CartSession::flash($error->getMessage());
            self::redirect(home_url('/account/'));
        }

        $message = $result['added'] === 0
            ? 'Nothing from that order is available right now.'
            : sprintf('Added %d item%s from that order to your cart.', $result['added'], $result['added'] === 1 ? '' : 's');

        if ($result['skipped'] !== []) {
            $message .= ' Not available: ' . implode(', ', $result['skipped']) . '.';
        }

        CartSession::flash($message);

        self::redirect($result['added'] > 0 ? home_url('/cart/') : home_url('/account/'));
    }

    /**
     * @return array{phone:string,data:array{name:string,group_id:int,business_name:?string,email:?string}}|null
     */
    public static function pendingSignup(): ?array
    {
        $challenge = $_COOKIE[self::SIGNUP_COOKIE] ?? null;

        if (!is_string($challenge) || preg_match('/^[a-f0-9]{32}$/', $challenge) !== 1) {
            return null;
        }

        $pending = get_transient(self::SIGNUP_KEY . hash('sha256', $challenge));

        return is_array($pending) && isset($pending['phone'], $pending['data']) ? $pending : null;
    }

    private static function setSignupCookie(string $challenge): void
    {
        setcookie(self::SIGNUP_COOKIE, $challenge, [
            'expires' => time() + 600,
            'path' => defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
            'secure' => function_exists('is_ssl') && is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::SIGNUP_COOKIE] = $challenge;
    }

    private static function clearSignup(): void
    {
        $challenge = $_COOKIE[self::SIGNUP_COOKIE] ?? null;

        if (is_string($challenge) && preg_match('/^[a-f0-9]{32}$/', $challenge) === 1) {
            delete_transient(self::SIGNUP_KEY . hash('sha256', $challenge));
        }

        setcookie(self::SIGNUP_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
            'secure' => function_exists('is_ssl') && is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::SIGNUP_COOKIE]);
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
