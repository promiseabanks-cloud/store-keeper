<?php

/**
 * Plugin Name: Store Keeper Platform
 * Description: The Owner's platform layer: merchant applications, plans, tenant records, billing and provisioning (Phase 6).
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Author: Store Keeper
 * License: Proprietary
 * Network: true
 * Text Domain: storekeeper
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$storekeeperPlatformAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_file($storekeeperPlatformAutoload)) {
    require_once $storekeeperPlatformAutoload;
}

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Database\Migrator;
use StoreKeeper\Commerce\Core\Database\WpdbConnection;

/**
 * Network activation: create the platform tables once at network level (§8.6).
 * Subsite provisioning (§17.2) runs the per-store migrations.
 */
register_activation_hook(__FILE__, static function (): void {
    global $wpdb;

    $connection = new WpdbConnection($wpdb);
    (new Migrator($connection, dirname(__DIR__, 2) . '/plugins/storekeeper-commerce/migrations/network', $connection->networkPrefix()))->migrate();

    if (function_exists('update_site_option')) {
        update_site_option(Brand::OPTION_PREFIX . 'platform_schema', '0001_platform');
    }
});
