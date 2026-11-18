<?php

declare(strict_types=1);

namespace StoreKeeper\Platform;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Container;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Database\WpdbConnection;
use StoreKeeper\Commerce\Core\SystemClock;
use StoreKeeper\Platform\Applications\ApplicationService;
use StoreKeeper\Platform\Audit\PlatformAudit;
use StoreKeeper\Platform\Billing\BillingService;
use StoreKeeper\Platform\Console\OwnerConsole;
use StoreKeeper\Platform\Http\WebhookController;
use StoreKeeper\Platform\Plans\PlanGate;
use StoreKeeper\Platform\Plans\PlanRepository;
use StoreKeeper\Platform\Plans\PlanService;
use StoreKeeper\Platform\Plans\UsageRepository;
use StoreKeeper\Platform\Provisioning\ProvisioningService;
use StoreKeeper\Platform\Provisioning\WordPressProvisioner;
use StoreKeeper\Platform\Tenants\TenantRepository;

/**
 * The platform container (§17). Network-scoped: every table it touches uses the
 * network prefix, because these rows belong to the Owner, not a subsite.
 */
final class Platform
{
    private static ?Container $container = null;

    public static function container(): Container
    {
        if (self::$container !== null) {
            return self::$container;
        }

        global $wpdb;

        $container = new Container();
        $clock = new SystemClock();
        $connection = new WpdbConnection($wpdb);

        $container->instance('db', $connection);
        $container->instance(Connection::class, $connection);
        $container->instance(Clock::class, $clock);

        $container->singleton(PlatformAudit::class, static fn (Container $c): PlatformAudit => new PlatformAudit(
            $c->get(Connection::class),
            $c->get(Clock::class)
        ));
        $container->singleton(UsageRepository::class, static fn (Container $c): UsageRepository => new UsageRepository(
            $c->get(Connection::class),
            $c->get(Clock::class)
        ));
        $container->singleton(PlanRepository::class, static fn (Container $c): PlanRepository => new PlanRepository(
            $c->get(Connection::class)
        ));
        $container->singleton(PlanService::class, static fn (Container $c): PlanService => new PlanService(
            $c->get(PlanRepository::class),
            $c->get(PlatformAudit::class),
            $c->get(Clock::class)
        ));
        $container->singleton(PlanGate::class, static fn (Container $c): PlanGate => new PlanGate(
            $c->get(PlanRepository::class)
        ));
        $container->singleton(ApplicationService::class, static fn (Container $c): ApplicationService => new ApplicationService(
            $c->get(Connection::class),
            $c->get(PlatformAudit::class),
            $c->get(Clock::class)
        ));
        $container->singleton(TenantRepository::class, static fn (Container $c): TenantRepository => new TenantRepository(
            $c->get(Connection::class),
            $c->get(Clock::class)
        ));
        $container->singleton(BillingService::class, static fn (Container $c): BillingService => new BillingService(
            $c->get(Connection::class),
            $c->get(TenantRepository::class),
            $c->get(PlatformAudit::class),
            $c->get(Clock::class),
            (int) get_site_option(\StoreKeeper\Commerce\Core\Brand::OPTION_PREFIX . 'platform_grace_days', 7),
            14
        ));
        $container->singleton(WordPressProvisioner::class, static fn (): WordPressProvisioner => new WordPressProvisioner());
        $container->singleton(ProvisioningService::class, static fn (Container $c): ProvisioningService => new ProvisioningService(
            $c->get(Connection::class),
            $c->get(TenantRepository::class),
            $c->get(ApplicationService::class),
            $c->get(PlanRepository::class),
            $c->get(PlanGate::class),
            $c->get(WordPressProvisioner::class),
            $c->get(PlatformAudit::class),
            $c->get(Clock::class),
            (int) get_site_option(\StoreKeeper\Commerce\Core\Brand::OPTION_PREFIX . 'platform_trial_days', 14)
        ));

        return self::$container = $container;
    }

    public static function boot(): void
    {
        $container = self::container();

        OwnerConsole::register();

        add_action('rest_api_init', static function () use ($container): void {
            (new WebhookController($container->get(BillingService::class)))->register();
        });

        // §17.4: the dunning ladder moves tenants without deleting anything.
        add_action('storekeeper_platform_dunning', static function () use ($container): void {
            $container->get(BillingService::class)->runDunning();
        });

        add_action('init', static function (): void {
            if (function_exists('wp_next_scheduled') && !wp_next_scheduled('storekeeper_platform_dunning')) {
                wp_schedule_event(time() + 900, 'hourly', 'storekeeper_platform_dunning');
            }
        }, 30);
    }

    /** Network migrations (0001, 0002, …). */
    public static function migrationsRoot(): string
    {
        return dirname(__DIR__, 2) . '/storekeeper-commerce/migrations/network';
    }

    public static function reset(): void
    {
        self::$container = null;
    }
}
