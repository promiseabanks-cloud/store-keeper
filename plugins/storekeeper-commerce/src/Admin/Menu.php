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

        // The plugin screen is its own app shell, so it marks the body rather
        // than fighting WordPress styles from the inside.
        add_filter('admin_body_class', static function (string $classes): string {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;

            if ($screen !== null && str_contains((string) $screen->id, self::SLUG)) {
                return trim($classes . ' storekeeper-app');
            }

            return $classes;
        });

        add_action('admin_enqueue_scripts', static function (string $hook): void {
            if (!str_contains($hook, self::SLUG)) {
                return;
            }

            $base = dirname(__DIR__, 2);
            $script = $base . '/assets/admin/admin.js';
            $screens = $base . '/assets/admin/screens.js';
            $style = $base . '/assets/admin/admin.css';

            wp_enqueue_style(
                'storekeeper-admin',
                plugins_url('assets/admin/admin.css', $base . '/storekeeper-commerce.php'),
                [],
                is_file($style) ? (string) filemtime($style) : '0.1.0'
            );

            // Shipping, parcels and messages load first so admin.js can render
            // them from window.storekeeperScreens.
            wp_enqueue_script(
                'storekeeper-screens',
                plugins_url('assets/admin/screens.js', $base . '/storekeeper-commerce.php'),
                ['wp-element', 'wp-api-fetch'],
                is_file($screens) ? (string) filemtime($screens) : '0.1.0',
                true
            );

            wp_enqueue_script(
                'storekeeper-admin',
                plugins_url('assets/admin/admin.js', $base . '/storekeeper-commerce.php'),
                ['wp-element', 'wp-api-fetch', 'storekeeper-screens'],
                is_file($script) ? (string) filemtime($script) : '0.1.0',
                true
            );

            wp_localize_script('storekeeper-admin', 'storekeeperAdmin', [
                'root' => esc_url_raw(rest_url(Brand::REST_NAMESPACE)),
                'nonce' => wp_create_nonce('wp_rest'),
                'currency' => Brand::CURRENCY,
                'storeName' => get_bloginfo('name'),
                'userName' => wp_get_current_user()->display_name,
                'wpAdminUrl' => admin_url(),
                'siteUrl' => home_url('/'),
                'logoutUrl' => wp_logout_url(),
            ]);
        });
    }

    public static function render(): void
    {
        echo '<div class="sk-root" id="storekeeper-admin"><p class="storekeeper-loading">Loading your store…</p></div>';
    }
}
