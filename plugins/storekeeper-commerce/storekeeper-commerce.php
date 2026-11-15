<?php

/**
 * Plugin Name: Store Keeper Commerce
 * Description: The Store Keeper commerce engine: catalog, pricing, stock ledger, orders, customers and the REST API.
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

$storekeeperAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_file($storekeeperAutoload)) {
    require_once $storekeeperAutoload;
}

use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Installer;
use StoreKeeper\Commerce\Core\Plugin;
use StoreKeeper\Commerce\Core\Idempotency\IdempotencyService;
use StoreKeeper\Commerce\Core\Audit\AuditLogger;
use StoreKeeper\Commerce\Rest\Api;

/**
 * Activation: create/upgrade this site's tables and register the store roles.
 * Network activation runs again per site on the network upgrade command (§7.3).
 */
register_activation_hook(__FILE__, static function (): void {
    $container = Plugin::container();
    (new Installer($container->get(Connection::class), Plugin::migrationsRoot()))->activate();
});

add_action('plugins_loaded', static function (): void {
    if (!is_multisite()) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('Store Keeper requires WordPress multisite.', 'storekeeper')
                . '</p></div>';
        });

        return;
    }

    Plugin::boot();
});

add_action('plugins_loaded', static function (): void {
    $container = Plugin::container();
    (new Api(
        $container->get(IdempotencyService::class),
        $container->get(AuditLogger::class)
    ))->register();
}, 20);

/**
 * WP-CLI commands (§9.6, §7.3). Cron and operators use these instead of
 * touching the tables by hand.
 */
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('storekeeper migrate', static function (): \StoreKeeper\Commerce\Cli\MigrateCommand {
        return new \StoreKeeper\Commerce\Cli\MigrateCommand();
    });
    \WP_CLI::add_command('storekeeper stock', static function (): \StoreKeeper\Commerce\Cli\StockCommand {
        return new \StoreKeeper\Commerce\Cli\StockCommand(Plugin::container()->get(\StoreKeeper\Commerce\Inventory\InventoryService::class));
    });
}
