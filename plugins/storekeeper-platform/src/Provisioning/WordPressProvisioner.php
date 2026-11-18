<?php

declare(strict_types=1);

namespace StoreKeeper\Platform\Provisioning;

use StoreKeeper\Commerce\Core\Database\WpdbConnection;
use StoreKeeper\Commerce\Core\Installer;
use StoreKeeper\Commerce\Core\Plugin;

/**
 * WordPress implementation of provisioning (§17.2): create the subsite, run the
 * store migrations and seed defaults, attach the owner and activate the theme.
 *
 * Custom domains and SSL are hosting concerns and are not built here (Q-019).
 */
final class WordPressProvisioner implements SiteProvisioner
{
    public function createSite(string $slug, string $title, int $ownerUserId): int
    {
        if (!function_exists('wpmu_create_blog')) {
            throw new \RuntimeException('Multisite is required to provision a store.');
        }

        $host = (string) parse_url(network_home_url(), PHP_URL_HOST);
        $path = '/' . trim($slug, '/') . '/';

        $blogId = wpmu_create_blog($host, $path, $title, $ownerUserId, ['public' => 1], get_current_network_id());

        if (is_wp_error($blogId)) {
            throw new \RuntimeException('Could not create the site: ' . $blogId->get_error_message());
        }

        return (int) $blogId;
    }

    public function seedStore(int $blogId, array $plan, string $storeMode): void
    {
        global $wpdb;

        switch_to_blog($blogId);

        try {
            $connection = new WpdbConnection($wpdb);
            (new Installer($connection, Plugin::migrationsRoot()))->activate();

            update_option('blogname', get_bloginfo('name'));
            update_option('storekeeper_store_mode', $storeMode);
            update_option('storekeeper_plan_features', wp_json_encode($plan['features'] ?? []));
            update_option('storekeeper_plan_limits', wp_json_encode($plan['limits'] ?? []));
            update_option('timezone_string', 'Africa/Lagos');
            update_option('woocommerce_currency', 'NGN');

            $theme = 'storekeeper-storefront';
            if (wp_get_theme($theme)->exists()) {
                switch_theme($theme);
            }

            \StoreKeeper\Commerce\Storefront\Storefront::activate();
        } finally {
            restore_current_blog();
        }
    }

    public function finish(int $blogId, int $ownerUserId, string $businessName): void
    {
        switch_to_blog($blogId);

        try {
            update_option('blogname', $businessName);
            update_option('default_role', 'storekeeper_sales');
            update_option('storekeeper_onboarding_checklist', wp_json_encode([
                'store_name' => true,
                'location' => true,
                'shipping' => true,
                'products' => false,
                'payments' => false,
                'pos_device' => false,
            ]));

            if (function_exists('add_user_to_blog') && get_userdata($ownerUserId) !== false) {
                add_user_to_blog($blogId, $ownerUserId, 'storekeeper_owner');
            }
        } finally {
            restore_current_blog();
        }
    }
}
