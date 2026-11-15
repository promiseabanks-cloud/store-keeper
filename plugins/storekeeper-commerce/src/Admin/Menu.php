<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Admin;

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Capabilities;

/**
 * Registers the back office inside wp-admin (§6.1, §11.7).
 *
 * The screens themselves are a React app (wp.element + wp.apiFetch) that talks
 * only to the REST API, never to the database.
 */
final class Menu
{
    public const SLUG = 'storekeeper';

    public static function register(): void
    {
        add_action('admin_menu', static function (): void {
            add_menu_page(
                Brand::NAME,
                Brand::NAME,
                Capabilities::wp(Capabilities::VIEW_REVENUE),
                self::SLUG,
                [self::class, 'render'],
                'dashicons-store',
                3
            );

            add_submenu_page(
                self::SLUG,
                'Products',
                'Products',
                Capabilities::wp(Capabilities::EDIT_PRODUCTS),
                self::SLUG . '-products',
                [self::class, 'render']
            );
        });

        add_action('admin_enqueue_scripts', static function (string $hook): void {
            if (!str_contains($hook, self::SLUG)) {
                return;
            }

            $base = dirname(__DIR__, 2);
            $script = $base . '/assets/admin/admin.js';
            $style = $base . '/assets/admin/admin.css';

            wp_enqueue_style(
                'storekeeper-admin',
                plugins_url('assets/admin/admin.css', $base . '/storekeeper-commerce.php'),
                [],
                is_file($style) ? (string) filemtime($style) : '0.1.0'
            );

            wp_enqueue_script(
                'storekeeper-admin',
                plugins_url('assets/admin/admin.js', $base . '/storekeeper-commerce.php'),
                ['wp-element', 'wp-api-fetch'],
                is_file($script) ? (string) filemtime($script) : '0.1.0',
                true
            );

            wp_localize_script('storekeeper-admin', 'storekeeperAdmin', [
                'root' => esc_url_raw(rest_url(Brand::REST_NAMESPACE)),
                'nonce' => wp_create_nonce('wp_rest'),
                'currency' => Brand::CURRENCY,
                'storeName' => get_bloginfo('name'),
            ]);
        });
    }

    public static function render(): void
    {
        echo '<div class="wrap storekeeper-wrap">';
        echo '<h1>' . esc_html(Brand::NAME) . '</h1>';
        echo '<div id="storekeeper-admin"><p class="storekeeper-loading">Loading your store…</p></div>';
        echo '</div>';
    }
}
