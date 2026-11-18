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

    // Storefront URLs (/shop/, /product/{slug}/) need their rules on disk.
    \StoreKeeper\Commerce\Storefront\Storefront::activate();
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

    // Phase 2 screens: the back office menu and its REST endpoints.
    \StoreKeeper\Commerce\Admin\Menu::register();

    // Phase 4: the storefront's routes, block and templates.
    $storefront = $container->get(\StoreKeeper\Commerce\Storefront\CatalogView::class);
    \StoreKeeper\Commerce\Storefront\Storefront::register($storefront);
    \StoreKeeper\Commerce\Storefront\Blocks::register($storefront);

    // Phase 5: the installable POS app at /pos/ (§15.1).
    \StoreKeeper\Commerce\Pos\PosPage::register();

    add_action('rest_api_init', static function () use ($container): void {
        $api = new Api(
            $container->get(IdempotencyService::class),
            $container->get(AuditLogger::class)
        );

        (new \StoreKeeper\Commerce\Rest\CatalogController(
            $container->get(\StoreKeeper\Commerce\Catalog\CatalogService::class),
            $container->get(\StoreKeeper\Commerce\Catalog\CatalogRepository::class),
            $api
        ))->register();

        (new \StoreKeeper\Commerce\Rest\InventoryController(
            $container->get(\StoreKeeper\Commerce\Inventory\InventoryService::class),
            $api
        ))->register();

        (new \StoreKeeper\Commerce\Rest\OverviewController(
            $container->get(\StoreKeeper\Commerce\Reports\OverviewService::class)
        ))->register();

        (new \StoreKeeper\Commerce\Rest\OrderController(
            $container->get(\StoreKeeper\Commerce\Orders\OrderService::class),
            $container->get(\StoreKeeper\Commerce\Orders\OrderRepository::class),
            $container->get(\StoreKeeper\Commerce\Customers\CustomerService::class),
            $container->get(\StoreKeeper\Commerce\Payments\PaymentService::class),
            $api,
            $container->get(\StoreKeeper\Commerce\Orders\ShipmentService::class)
        ))->register();

        (new \StoreKeeper\Commerce\Rest\ShipmentController(
            $container->get(\StoreKeeper\Commerce\Orders\ShipmentService::class),
            $container->get(\StoreKeeper\Commerce\Orders\OrderService::class),
            $api
        ))->register();

        (new \StoreKeeper\Commerce\Rest\ShippingController(
            $container->get(\StoreKeeper\Commerce\Shipping\ShippingService::class),
            $api
        ))->register();

        (new \StoreKeeper\Commerce\Rest\PosController(
            $container->get(\StoreKeeper\Commerce\Pos\DeviceService::class),
            $container->get(\StoreKeeper\Commerce\Pos\CounterService::class),
            $api,
            $container->get(\StoreKeeper\Commerce\Pos\DeviceGuard::class)
        ))->register();

        (new \StoreKeeper\Commerce\Rest\SyncController(
            $container->get(\StoreKeeper\Commerce\Pos\SyncService::class),
            $container->get(\StoreKeeper\Commerce\Pos\DeviceGuard::class)
        ))->register();

        (new \StoreKeeper\Commerce\Rest\WebhookController(
            $container->get(\StoreKeeper\Commerce\Payments\PaymentService::class)
        ))->register();

        (new \StoreKeeper\Commerce\Rest\SettingsController(
            $container->get(\StoreKeeper\Commerce\Payments\GatewaySettings::class)
        ))->register();

        (new \StoreKeeper\Commerce\Rest\CustomerController(
            $container->get(\StoreKeeper\Commerce\Customers\CustomerService::class),
            $container->get(\StoreKeeper\Commerce\Customers\CustomerRepository::class),
            $container->get(\StoreKeeper\Commerce\Orders\OrderRepository::class),
            $api
        ))->register();

        (new \StoreKeeper\Commerce\Rest\PricingController(
            $container->get(\StoreKeeper\Commerce\Pricing\PricingService::class),
            $container->get(\StoreKeeper\Commerce\Pricing\PricingRepository::class),
            $container->get(\StoreKeeper\Commerce\Customers\CustomerService::class),
            $api
        ))->register();

        (new \StoreKeeper\Commerce\Rest\AlertsController(
            $container->get(\StoreKeeper\Commerce\Alerts\AlertService::class)
        ))->register();

        (new \StoreKeeper\Commerce\Rest\StaffController())->register();
    });
}, 20);

/**
 * WP-CLI commands (§9.6, §7.3). Cron and operators use these instead of
 * touching the tables by hand.
 */
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('storekeeper migrate', \StoreKeeper\Commerce\Cli\MigrateCommand::class);
    \WP_CLI::add_command(
        'storekeeper stock',
        new \StoreKeeper\Commerce\Cli\StockCommand(Plugin::container()->get(\StoreKeeper\Commerce\Inventory\InventoryService::class))
    );
    \WP_CLI::add_command(
        'storekeeper orders expire-holds',
        [
            new \StoreKeeper\Commerce\Cli\OrderCommand(Plugin::container()->get(\StoreKeeper\Commerce\Orders\OrderService::class)),
            'expireHolds',
        ]
    );
    \WP_CLI::add_command(
        'storekeeper messages dispatch',
        [
            new \StoreKeeper\Commerce\Cli\MessageCommand(Plugin::container()->get(\StoreKeeper\Commerce\Notifications\MessageService::class)),
            'dispatch',
        ]
    );
}
