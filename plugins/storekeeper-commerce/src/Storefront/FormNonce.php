<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront;

/**
 * Shared nonce check for the storefront's `admin-post.php` forms (D-028).
 *
 * WordPress nonces expire after 24 hours, so a page left open overnight is
 * normal shopper behaviour, not an attack. A failure changes nothing, explains
 * itself and returns to a freshly rendered form instead of dying in
 * `wp_nonce_ays()`.
 */
final class FormNonce
{
    public static function verify(string $action, string $expiredMessage, string $fallbackUrl): void
    {
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash((string) $_POST['_wpnonce'])) : '';

        if ($nonce !== '' && wp_verify_nonce($nonce, $action) !== false) {
            return;
        }

        CartSession::flash($expiredMessage);

        wp_safe_redirect($fallbackUrl);
        exit;
    }
}
