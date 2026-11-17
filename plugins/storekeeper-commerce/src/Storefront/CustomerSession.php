<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront;

use StoreKeeper\Commerce\Core\Plugin;

/**
 * The browser's storefront session cookie (§7.4).
 *
 * The cookie carries 32 random bytes; the database stores only their SHA-256.
 * It is HTTP-only and SameSite=Lax, and secure whenever the page is HTTPS.
 */
final class CustomerSession
{
    public const COOKIE = 'skf_session';

    public static function token(): ?string
    {
        $raw = $_COOKIE[self::COOKIE] ?? null;

        return is_string($raw) && preg_match('/^[a-f0-9]{64}$/', $raw) === 1 ? $raw : null;
    }

    public static function establish(string $token): void
    {
        if (!headers_sent()) {
            setcookie(self::COOKIE, $token, [
                'expires' => time() + 90 * 86400,
                'path' => defined('COOKIEPATH') && COOKIEPATH !== '' ? COOKIEPATH : '/',
                'domain' => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
                'secure' => function_exists('is_ssl') && is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        $_COOKIE[self::COOKIE] = $token;
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

    /** @return array<string,mixed>|null the signed-in customer for this cookie */
    public static function current(): ?array
    {
        $token = self::token();

        if ($token === null) {
            return null;
        }

        return Plugin::container()
            ->get(\StoreKeeper\Commerce\Customers\StorefrontAuth::class)
            ->customerFor($token);
    }
}
