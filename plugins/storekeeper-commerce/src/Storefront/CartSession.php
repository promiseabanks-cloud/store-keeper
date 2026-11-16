<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront;

use StoreKeeper\Commerce\Cart\CartService;
use StoreKeeper\Commerce\Core\Plugin;

/**
 * The browser's cart identity (§22.5, D-026).
 *
 * The cookie carries 32 random bytes; the database stores only their SHA-256.
 * A stolen row therefore cannot be replayed as a cookie, and nothing about the
 * cart's contents or prices is ever sent to the browser except in the page.
 */
final class CartSession
{
    public const COOKIE = 'skf_cart';

    /** A cart is created on the first write, never on a plain page view. */
    public static function cart(bool $create = false): ?array
    {
        $token = self::token();

        if ($token === null) {
            if (!$create) {
                return null;
            }
            $token = self::createToken();
        }

        $cart = self::service()->find(self::hash($token));

        if ($cart === null && $create) {
            $cart = self::service()->open(self::hash($token), self::customerId());
        }

        return $cart;
    }

    /** @return array<string,mixed>|null */
    public static function summary(bool $create = false): ?array
    {
        $cart = self::cart($create);

        if ($cart === null) {
            return null;
        }

        return self::service()->summary($cart, self::customer());
    }

    public static function count(): int
    {
        try {
            $cart = self::cart(false);

            return $cart === null ? 0 : self::service()->lineCount($cart);
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function token(): ?string
    {
        $raw = $_COOKIE[self::COOKIE] ?? null;

        if (!is_string($raw) || preg_match('/^[a-f0-9]{64}$/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The token to use for an action triggered by a form that posted no cookie. */
    public static function createToken(): string
    {
        $token = bin2hex(random_bytes(32));

        if (!headers_sent()) {
            setcookie(self::COOKIE, $token, [
                'expires' => time() + 30 * 86400,
                'path' => defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/',
                'domain' => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
                'secure' => function_exists('is_ssl') && is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        $_COOKIE[self::COOKIE] = $token;

        return $token;
    }

    public static function forget(): void
    {
        if (!headers_sent()) {
            setcookie(self::COOKIE, '', [
                'expires' => time() - 3600,
                'path' => defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/',
                'domain' => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
                'secure' => function_exists('is_ssl') && is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        unset($_COOKIE[self::COOKIE]);
    }

    /** A one-shot message for the next cart or checkout page. */
    public static function flash(string $message): void
    {
        $token = self::token();

        if ($token !== null && function_exists('set_transient')) {
            set_transient('skf_cart_error_' . self::hash($token), $message, 60);
        }
    }

    public static function takeFlash(): ?string
    {
        $token = self::token();

        if ($token === null || !function_exists('get_transient')) {
            return null;
        }

        $key = 'skf_cart_error_' . self::hash($token);
        $message = get_transient($key);

        if (is_string($message) && $message !== '') {
            delete_transient($key);

            return $message;
        }

        return null;
    }

    private static function service(): CartService
    {
        return Plugin::container()->get(CartService::class);
    }

    /** @return array<string,mixed>|null */
    private static function customer(): ?array
    {
        return Plugin::container()->get(CustomerContext::class)->current();
    }

    private static function customerId(): ?int
    {
        $customer = self::customer();

        return $customer === null ? null : (int) $customer['id'];
    }
}
