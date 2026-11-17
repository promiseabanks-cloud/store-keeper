<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront;

use StoreKeeper\Commerce\Cart\CartService;
use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;
use StoreKeeper\Commerce\Core\Plugin;
use StoreKeeper\Commerce\Customers\StorefrontAuth;

/**
 * Phone + one-time-code sign-in for the storefront (§22.5).
 *
 * Three `admin-post.php` forms, all nonce-checked, so sign-in works without
 * JavaScript like the rest of the shop (D-028). The service owns the code and
 * session rules; this class only moves values between the request, the
 * short-lived challenge cookie and the session cookie.
 */
final class AuthActions
{
    public const CHALLENGE_COOKIE = 'skf_otp';

    private const CHALLENGE_KEY = 'storekeeper_otp_challenge_';

    public static function register(): void
    {
        foreach ([
            'storekeeper_otp_request' => 'request',
            'storekeeper_otp_verify' => 'verify',
            'storekeeper_sign_out' => 'signOut',
        ] as $action => $method) {
            add_action('admin_post_' . $action, [self::class, $method]);
            add_action('admin_post_nopriv_' . $action, [self::class, $method]);
        }
    }

    public static function request(): void
    {
        FormNonce::verify(
            'storekeeper_otp_request',
            'This page had expired, so no code was sent. Please try again.',
            home_url('/sign-in/')
        );

        $phone = (string) ($_POST['phone'] ?? '');

        try {
            $result = self::auth()->request($phone, self::ip());
        } catch (StoreKeeperException $error) {
            CartSession::flash($error->getMessage());
            self::redirect(home_url('/sign-in/'));
        }

        $challenge = bin2hex(random_bytes(16));
        set_transient(self::CHALLENGE_KEY . hash('sha256', $challenge), $result['phone'], self::codeTtl());
        self::setChallengeCookie($challenge);

        CartSession::flash($result['sent']
            ? 'We sent a 6-digit code to ' . self::mask($result['phone']) . '.'
            : 'If that number has an account, a code is on its way.');

        self::redirect(home_url('/sign-in/'));
    }

    public static function verify(): void
    {
        FormNonce::verify(
            'storekeeper_otp_verify',
            'This page had expired, so the code was not checked. Please try again.',
            home_url('/sign-in/')
        );

        $phone = self::challengedPhone();

        if ($phone === null) {
            CartSession::flash('That sign-in attempt had expired. Please start again.');
            self::redirect(home_url('/sign-in/'));
        }

        try {
            $result = self::auth()->verify(
                $phone,
                (string) ($_POST['code'] ?? ''),
                self::ip(),
                isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null
            );
        } catch (StoreKeeperException $error) {
            CartSession::flash($error->getMessage());
            self::redirect(home_url('/sign-in/'));
        }

        self::clearChallenge();
        CustomerSession::establish($result['token']);

        // Items picked before signing in belong to this shopper now.
        $cart = CartSession::cart(false);

        if ($cart !== null) {
            $cart = Plugin::container()->get(CartService::class)->claim($cart, (int) $result['customer']['id']);
        }

        CartSession::flash('Welcome back, ' . self::firstName((string) $result['customer']['name']) . '.');

        self::redirect($cart !== null && Plugin::container()->get(CartService::class)->lineCount($cart) > 0
            ? home_url('/checkout/')
            : home_url('/shop/'));
    }

    public static function signOut(): void
    {
        FormNonce::verify(
            'storekeeper_sign_out',
            'This page had expired, so you are still signed in.',
            home_url('/sign-in/')
        );

        $token = CustomerSession::token();

        if ($token !== null) {
            self::auth()->signOut($token);
        }

        CustomerSession::forget();
        CartSession::flash('You are signed out.');

        self::redirect(home_url('/'));
    }

    /** The phone number behind this browser's pending challenge, if still valid. */
    public static function challengedPhone(): ?string
    {
        $challenge = $_COOKIE[self::CHALLENGE_COOKIE] ?? null;

        if (!is_string($challenge) || preg_match('/^[a-f0-9]{32}$/', $challenge) !== 1) {
            return null;
        }

        $phone = get_transient(self::CHALLENGE_KEY . hash('sha256', $challenge));

        return is_string($phone) && $phone !== '' ? $phone : null;
    }

    public static function mask(string $phoneE164): string
    {
        return strlen($phoneE164) < 7
            ? $phoneE164
            : substr($phoneE164, 0, 4) . str_repeat('•', 3) . substr($phoneE164, -4);
    }

    private static function clearChallenge(): void
    {
        $challenge = $_COOKIE[self::CHALLENGE_COOKIE] ?? null;

        if (is_string($challenge) && preg_match('/^[a-f0-9]{32}$/', $challenge) === 1) {
            delete_transient(self::CHALLENGE_KEY . hash('sha256', $challenge));
        }

        setcookie(self::CHALLENGE_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
            'secure' => function_exists('is_ssl') && is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::CHALLENGE_COOKIE]);
    }

    private static function setChallengeCookie(string $challenge): void
    {
        setcookie(self::CHALLENGE_COOKIE, $challenge, [
            'expires' => time() + self::codeTtl(),
            'path' => defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
            'secure' => function_exists('is_ssl') && is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::CHALLENGE_COOKIE] = $challenge;
    }

    private static function codeTtl(): int
    {
        return StorefrontAuth::CODE_TTL_MINUTES * 60;
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

    private static function auth(): StorefrontAuth
    {
        return Plugin::container()->get(StorefrontAuth::class);
    }

    private static function redirect(string $url): void
    {
        wp_safe_redirect($url);
        exit;
    }
}
