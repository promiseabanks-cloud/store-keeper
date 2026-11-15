<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core;

use StoreKeeper\Commerce\Alerts\AlertService;
use StoreKeeper\Commerce\Catalog\CatalogRepository;
use StoreKeeper\Commerce\Catalog\CatalogService;
use StoreKeeper\Commerce\Core\Audit\DatabaseAuditLogger;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Database\WpdbConnection;
use StoreKeeper\Commerce\Core\Hooks\WpHooks;
use StoreKeeper\Commerce\Core\Idempotency\IdempotencyService;
use StoreKeeper\Commerce\Customers\CustomerRepository;
use StoreKeeper\Commerce\Customers\CustomerService;
use StoreKeeper\Commerce\Inventory\InventoryRepository;
use StoreKeeper\Commerce\Inventory\InventoryService;
use StoreKeeper\Commerce\Orders\OrderRepository;
use StoreKeeper\Commerce\Orders\OrderService;
use StoreKeeper\Commerce\Payments\Gateway\GatewayRegistry;
use StoreKeeper\Commerce\Payments\Gateway\WordPressHttpClient;
use StoreKeeper\Commerce\Payments\GatewaySettings;
use StoreKeeper\Commerce\Payments\PaymentRepository;
use StoreKeeper\Commerce\Payments\PaymentService;
use StoreKeeper\Commerce\Pricing\PricingRepository;
use StoreKeeper\Commerce\Pricing\PricingService;
use StoreKeeper\Commerce\Reports\OverviewService;

/**
 * Builds the engine container for the current site (§6.1, §7.3).
 *
 * Nothing here reads the request or renders HTML: screens call the REST API,
 * the API calls these services, and only the services write to the tables.
 */
final class Plugin
{
    private static ?Container $container = null;

    public static function migrationsRoot(): string
    {
        return dirname(__DIR__, 2) . '/migrations';
    }

    public static function container(): Container
    {
        if (self::$container !== null) {
            return self::$container;
        }

        global $wpdb;
        $container = new Container();

        $container->instance('db', new WpdbConnection($wpdb));
        $clock = new SystemClock();
        $container->instance(Clock::class, $clock);
        $container->instance(Hooks\Hooks::class, new WpHooks());

        $container->singleton(Audit\DatabaseAuditLogger::class, static fn (Container $c): DatabaseAuditLogger => new DatabaseAuditLogger(
            $c->get(Connection::class),
            $c->get(Clock::class)
        ));
        $container->singleton(AlertService::class, static fn (Container $c): AlertService => new AlertService(
            $c->get(Connection::class),
            $c->get(Clock::class)
        ));
        $container->singleton(IdempotencyService::class, static fn (Container $c): IdempotencyService => new IdempotencyService(
            $c->get(Connection::class),
            $c->get(Clock::class)
        ));

        $container->singleton(PricingRepository::class, static fn (Container $c): PricingRepository => new PricingRepository(
            $c->get(Connection::class),
            $c->get(Clock::class)
        ));
        $container->singleton(PricingService::class, static fn (Container $c): PricingService => new PricingService(
            $c->get(PricingRepository::class),
            $c->get(Clock::class),
            $c->get(Audit\AuditLogger::class),
            (bool) get_option(Brand::OPTION_PREFIX . 'derive_pack_prices', true)
        ));

        $container->singleton(InventoryRepository::class, static fn (Container $c): InventoryRepository => new InventoryRepository(
            $c->get(Connection::class),
            $c->get(Clock::class)
        ));
        $container->singleton(InventoryService::class, static fn (Container $c): InventoryService => new InventoryService(
            $c->get(Connection::class),
            $c->get(InventoryRepository::class),
            $c->get(Hooks\Hooks::class),
            $c->get(Clock::class),
            $c->get(AlertService::class),
            (int) get_option(Brand::OPTION_PREFIX . 'low_stock_default', 5)
        ));

        $container->singleton(CatalogRepository::class, static fn (Container $c): CatalogRepository => new CatalogRepository(
            $c->get(Connection::class),
            $c->get(Clock::class)
        ));
        $container->singleton(CatalogService::class, static fn (Container $c): CatalogService => new CatalogService(
            $c->get(Connection::class),
            $c->get(CatalogRepository::class),
            $c->get(InventoryService::class),
            $c->get(PricingRepository::class),
            $c->get(Hooks\Hooks::class),
            $c->get(Audit\AuditLogger::class),
            $c->get(Clock::class)
        ));

        $container->singleton(CustomerRepository::class, static fn (Container $c): CustomerRepository => new CustomerRepository(
            $c->get(Connection::class),
            $c->get(Clock::class)
        ));
        $container->singleton(CustomerService::class, static fn (Container $c): CustomerService => new CustomerService(
            $c->get(Connection::class),
            $c->get(CustomerRepository::class),
            $c->get(PricingRepository::class),
            $c->get(Hooks\Hooks::class),
            $c->get(Audit\AuditLogger::class),
            $c->get(Clock::class)
        ));

        $container->singleton(OrderRepository::class, static fn (Container $c): OrderRepository => new OrderRepository(
            $c->get(Connection::class),
            $c->get(Clock::class)
        ));
        $container->singleton(OrderService::class, static fn (Container $c): OrderService => new OrderService(
            $c->get(Connection::class),
            $c->get(OrderRepository::class),
            $c->get(CatalogRepository::class),
            $c->get(PricingService::class),
            $c->get(InventoryService::class),
            $c->get(Hooks\Hooks::class),
            $c->get(Audit\AuditLogger::class),
            $c->get(Clock::class),
            (string) get_option(Brand::OPTION_PREFIX . 'order_prefix', 'SK')
        ));

        $container->singleton(OverviewService::class, static fn (Container $c): OverviewService => new OverviewService(
            $c->get(Connection::class)
        ));

        $container->singleton(GatewaySettings::class, static fn (Container $c): GatewaySettings => new GatewaySettings(
            $c->get(Audit\AuditLogger::class)
        ));

        $container->singleton(PaymentRepository::class, static fn (Container $c): PaymentRepository => new PaymentRepository(
            $c->get(Connection::class),
            $c->get(Clock::class)
        ));
        $container->singleton(GatewayRegistry::class, static fn (Container $c): GatewayRegistry => new GatewayRegistry(
            $c->get(GatewaySettings::class),
            new WordPressHttpClient()
        ));
        $container->singleton(PaymentService::class, static fn (Container $c): PaymentService => new PaymentService(
            $c->get(Connection::class),
            $c->get(PaymentRepository::class),
            $c->get(OrderService::class),
            $c->get(GatewayRegistry::class),
            $c->get(AlertService::class),
            $c->get(Audit\AuditLogger::class),
            $c->get(Clock::class),
            rest_url(Brand::REST_NAMESPACE . '/webhooks'),
            untrailingslashit(home_url('/'))
        ));

        // The engine's shared dependencies are looked up by interface.
        $container->instance(Connection::class, $container->get('db'));
        $container->instance(Audit\AuditLogger::class, $container->get(DatabaseAuditLogger::class));

        return self::$container = $container;
    }

    /** Called on plugins_loaded for site requests. */
    public static function boot(): void
    {
        $container = self::container();

        // A store request always runs with exactly one blog context (§6.4).
        add_action('init', static function () use ($container): void {
            if (!is_blog_installed()) {
                return;
            }

            $applied = (new SchemaMigrator($container->get(Connection::class), self::migrationsRoot()))->migrateAll();
            if ($applied['store'] !== [] || $applied['network'] !== []) {
                update_option(Brand::SCHEMA_VERSION_OPTION, Installer::schemaVersion());
            }
        }, 5);
    }

    /** Reset the container (tests and WP-CLI commands). */
    public static function reset(): void
    {
        self::$container = null;
    }
}
