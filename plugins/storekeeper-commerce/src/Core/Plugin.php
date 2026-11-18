<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core;

use StoreKeeper\Commerce\Alerts\AlertService;
use StoreKeeper\Commerce\Cart\CartRepository;
use StoreKeeper\Commerce\Cart\CartService;
use StoreKeeper\Commerce\Catalog\CatalogRepository;
use StoreKeeper\Commerce\Catalog\CatalogService;
use StoreKeeper\Commerce\Core\Audit\DatabaseAuditLogger;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Database\WpdbConnection;
use StoreKeeper\Commerce\Core\Hooks\WpHooks;
use StoreKeeper\Commerce\Core\Idempotency\IdempotencyService;
use StoreKeeper\Commerce\Checkout\CheckoutService;
use StoreKeeper\Commerce\Customers\CustomerRepository;
use StoreKeeper\Commerce\Customers\CustomerService;
use StoreKeeper\Commerce\Customers\CustomerSessionRepository;
use StoreKeeper\Commerce\Customers\OtpRepository;
use StoreKeeper\Commerce\Customers\StorefrontAuth;
use StoreKeeper\Commerce\Inventory\InventoryRepository;
use StoreKeeper\Commerce\Inventory\InventoryService;
use StoreKeeper\Commerce\Orders\OrderRepository;
use StoreKeeper\Commerce\Orders\OrderService;
use StoreKeeper\Commerce\Orders\ShipmentRepository;
use StoreKeeper\Commerce\Orders\ShipmentService;
use StoreKeeper\Commerce\Payments\Gateway\GatewayRegistry;
use StoreKeeper\Commerce\Pos\ChangeLog;
use StoreKeeper\Commerce\Pos\CounterService;
use StoreKeeper\Commerce\Pos\DeviceRepository;
use StoreKeeper\Commerce\Pos\DeviceService;
use StoreKeeper\Commerce\Pos\SyncRepository;
use StoreKeeper\Commerce\Pos\SyncService;
use StoreKeeper\Commerce\Payments\Gateway\WordPressHttpClient;
use StoreKeeper\Commerce\Payments\GatewaySettings;
use StoreKeeper\Commerce\Payments\PaymentRepository;
use StoreKeeper\Commerce\Payments\PaymentService;
use StoreKeeper\Commerce\Pricing\PricingRepository;
use StoreKeeper\Commerce\Pricing\PricingService;
use StoreKeeper\Commerce\Reports\OverviewService;
use StoreKeeper\Commerce\Notifications\EmailChannel;
use StoreKeeper\Commerce\Notifications\LogChannel;
use StoreKeeper\Commerce\Notifications\MessageLogRepository;
use StoreKeeper\Commerce\Notifications\MessageService;
use StoreKeeper\Commerce\Notifications\MessageTemplateRepository;
use StoreKeeper\Commerce\Notifications\OrderNotifications;
use StoreKeeper\Commerce\Storefront\CatalogView;
use StoreKeeper\Commerce\Storefront\CustomerContext;
use StoreKeeper\Commerce\Storefront\StorefrontAccount;
use StoreKeeper\Commerce\Storefront\StorefrontRegistration;
use StoreKeeper\Commerce\Storefront\CustomerSession;
use StoreKeeper\Commerce\Storefront\Otp\EmailOtpSender;
use StoreKeeper\Commerce\Storefront\Otp\LogOtpSender;
use StoreKeeper\Commerce\Storefront\Otp\OtpSender;
use StoreKeeper\Commerce\Storefront\Storefront;
use StoreKeeper\Commerce\Shipping\ShippingRepository;
use StoreKeeper\Commerce\Shipping\ShippingService;

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
            $c->get(CustomerRepository::class),
            (string) get_option(Brand::OPTION_PREFIX . 'order_prefix', 'SK')
        ));

        // Shipping (§13.3) and parcels (§14.1). Zones decide the fee; a parcel
        // commits its own stock and writes the timeline the customer sees.
        $container->singleton(ShippingRepository::class, static fn (Container $c): ShippingRepository => new ShippingRepository(
            $c->get(Connection::class)
        ));
        $container->singleton(ShippingService::class, static fn (Container $c): ShippingService => new ShippingService(
            $c->get(ShippingRepository::class),
            $c->get(Audit\AuditLogger::class)
        ));
        $container->singleton(ShipmentRepository::class, static fn (Container $c): ShipmentRepository => new ShipmentRepository(
            $c->get(Connection::class),
            $c->get(Clock::class)
        ));
        $container->singleton(ShipmentService::class, static fn (Container $c): ShipmentService => new ShipmentService(
            $c->get(Connection::class),
            $c->get(ShipmentRepository::class),
            $c->get(OrderRepository::class),
            $c->get(OrderService::class),
            $c->get(InventoryService::class),
            $c->get(Hooks\Hooks::class),
            $c->get(Audit\AuditLogger::class),
            $c->get(Clock::class)
        ));

        $container->singleton(OverviewService::class, static fn (Container $c): OverviewService => new OverviewService(
            $c->get(Connection::class)
        ));

        // Notifications (§12): messages are queued in-request and dispatched later.
        $container->singleton(MessageTemplateRepository::class, static fn (Container $c): MessageTemplateRepository => new MessageTemplateRepository(
            $c->get(Connection::class)
        ));
        $container->singleton(MessageLogRepository::class, static fn (Container $c): MessageLogRepository => new MessageLogRepository(
            $c->get(Connection::class)
        ));
        $container->singleton(MessageService::class, static function (Container $c): MessageService {
            // `log` is a preview-only transport: it writes to the error log and
            // counts as delivered, because a preview host has no mail server.
            $transport = (string) get_option(Brand::OPTION_PREFIX . 'notification_transport', 'email');
            $channels = ['email' => $transport === 'log' ? new LogChannel() : new EmailChannel()];

            return new MessageService(
                $c->get(MessageTemplateRepository::class),
                $c->get(MessageLogRepository::class),
                $channels,
                $c->get(Clock::class)
            );
        });
        $container->singleton(OrderNotifications::class, static fn (Container $c): OrderNotifications => new OrderNotifications(
            $c->get(MessageService::class)
        ));

        // Storefront sign-up and the account screen (§11.1): choose a group, and a
        // manual-approval group leaves the customer pending in the retail group.
        $container->singleton(StorefrontRegistration::class, static fn (Container $c): StorefrontRegistration => new StorefrontRegistration(
            $c->get(CustomerRepository::class),
            $c->get(CustomerService::class)
        ));
        $container->singleton(StorefrontAccount::class, static fn (Container $c): StorefrontAccount => new StorefrontAccount(
            $c->get(OrderRepository::class),
            $c->get(CartService::class),
            $c->get(CustomerRepository::class)
        ));

        // Storefront sign-in (§22.5): phone + OTP customers own a session cookie.
        $container->singleton(OtpRepository::class, static fn (Container $c): OtpRepository => new OtpRepository(
            $c->get(Connection::class)
        ));
        $container->singleton(CustomerSessionRepository::class, static fn (Container $c): CustomerSessionRepository => new CustomerSessionRepository(
            $c->get(Connection::class)
        ));
        $container->singleton(OtpSender::class, static function (Container $c): OtpSender {
            // A preview host has no mail server, so `log` (the same switch the
            // order emails read) keeps the code on screen instead of handing it
            // to a transport that cannot deliver.
            $transport = (string) get_option(Brand::OPTION_PREFIX . 'notification_transport', 'email');
            $sender = $transport === 'log'
                ? new LogOtpSender()
                : new EmailOtpSender(new EmailChannel(), new LogOtpSender());

            $filtered = apply_filters(Brand::hook('otp/sender'), $sender);

            return $filtered instanceof OtpSender ? $filtered : $sender;
        });
        $container->singleton(StorefrontAuth::class, static fn (Container $c): StorefrontAuth => new StorefrontAuth(
            $c->get(OtpRepository::class),
            $c->get(CustomerSessionRepository::class),
            $c->get(CustomerRepository::class),
            $c->get(OtpSender::class),
            $c->get(Clock::class)
        ));

        // Storefront read model (§22.5): prices always resolve through the engine.
        $container->singleton(CustomerContext::class, static fn (): CustomerContext => new CustomerContext(
            static fn (): ?array => CustomerSession::current()
        ));
        $container->singleton(CatalogView::class, static fn (Container $c): CatalogView => new CatalogView(
            $c->get(CatalogRepository::class),
            $c->get(PricingService::class),
            $c->get(PricingRepository::class),
            $c->get(InventoryRepository::class),
            $c->get(Connection::class),
            $c->get(CustomerContext::class)
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

        // Storefront cart and checkout (§22.5, D-026, D-027).
        $container->singleton(CartRepository::class, static fn (Container $c): CartRepository => new CartRepository(
            $c->get(Connection::class)
        ));
        $container->singleton(CartService::class, static fn (Container $c): CartService => new CartService(
            $c->get(CartRepository::class),
            $c->get(CatalogRepository::class),
            $c->get(PricingService::class),
            $c->get(InventoryRepository::class),
            $c->get(Clock::class)
        ));
        $container->singleton(CheckoutService::class, static fn (Container $c): CheckoutService => new CheckoutService(
            $c->get(CartRepository::class),
            $c->get(CartService::class),
            $c->get(OrderService::class),
            $c->get(CustomerRepository::class),
            (int) get_option(Brand::OPTION_PREFIX . 'flat_shipping_kobo', 0),
            $c->get(GatewayRegistry::class)->configuredSlugs(),
            $c->get(PaymentService::class),
            $c->get(ShippingService::class)
        ));

        // POS (§15.1): a registered device sells at its own location, and every
        // sale leaves a change the other devices can pull.
        $container->singleton(ChangeLog::class, static fn (Container $c): ChangeLog => new ChangeLog(
            $c->get(Connection::class),
            $c->get(Clock::class)
        ));
        $container->singleton(SyncRepository::class, static fn (Container $c): SyncRepository => new SyncRepository(
            $c->get(Connection::class)
        ));
        $container->singleton(DeviceRepository::class, static fn (Container $c): DeviceRepository => new DeviceRepository(
            $c->get(Connection::class),
            $c->get(Clock::class)
        ));
        $container->singleton(DeviceService::class, static fn (Container $c): DeviceService => new DeviceService(
            $c->get(Connection::class),
            $c->get(DeviceRepository::class),
            $c->get(InventoryRepository::class),
            $c->get(Audit\AuditLogger::class),
            $c->get(Clock::class)
        ));
        $container->singleton(CounterService::class, static fn (Container $c): CounterService => new CounterService(
            $c->get(Connection::class),
            $c->get(OrderService::class),
            $c->get(OrderRepository::class),
            $c->get(SyncRepository::class),
            $c->get(CatalogRepository::class),
            $c->get(PricingService::class),
            $c->get(InventoryService::class),
            $c->get(ChangeLog::class),
            $c->get(Audit\AuditLogger::class),
            $c->get(Clock::class),
            (int) get_option(Brand::OPTION_PREFIX . 'pos_price_change_threshold_bps', 500)
        ));
        $container->singleton(SyncService::class, static fn (Container $c): SyncService => new SyncService(
            $c->get(Connection::class),
            $c->get(SyncRepository::class),
            $c->get(ChangeLog::class),
            $c->get(CounterService::class),
            $c->get(CustomerService::class),
            $c->get(CustomerRepository::class),
            $c->get(OrderRepository::class),
            $c->get(DeviceService::class),
            $c->get(Audit\AuditLogger::class),
            $c->get(Clock::class)
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

        // §9.5: an unpaid hold expires and its stock is released. Hourly, and
        // also runnable by hand through `wp storekeeper orders expire-holds`.
        add_action('storekeeper_expire_holds', static function () use ($container): void {
            $container->get(OrderService::class)->expireHolds(200);
        });
        // §12.4: the outbox is drained by the same hourly tick.
        add_action('storekeeper_dispatch_messages', static function () use ($container): void {
            $container->get(MessageService::class)->dispatch(100);
        });
        add_action('init', static function (): void {
            if (!function_exists('wp_next_scheduled')) {
                return;
            }

            if (!wp_next_scheduled('storekeeper_expire_holds')) {
                wp_schedule_event(time() + 300, 'hourly', 'storekeeper_expire_holds');
            }

            if (!wp_next_scheduled('storekeeper_dispatch_messages')) {
                wp_schedule_event(time() + 600, 'hourly', 'storekeeper_dispatch_messages');
            }

            if (!wp_next_scheduled('storekeeper_check_devices')) {
                wp_schedule_event(time() + 900, 'hourly', 'storekeeper_check_devices');
            }
        }, 20);

        // §12.2: a checkout queues its confirmation; nothing is sent in-request.
        add_action(Brand::hook('order/created'), static function (array $payload) use ($container): void {
            $order = $payload['order'] ?? null;

            if (!is_array($order)) {
                return;
            }

            $address = json_decode((string) ($order['shipping_address'] ?? ''), true);
            $buyerEmail = is_array($address) ? ($address['email'] ?? null) : null;

            if ((!is_string($buyerEmail) || $buyerEmail === '') && !empty($order['customer_id'])) {
                $customer = $container->get(CustomerRepository::class)->customer((int) $order['customer_id']);
                $buyerEmail = $customer['email'] ?? null;
            }

            $staffEmail = (string) get_option(
                Brand::OPTION_PREFIX . 'notification_email',
                (string) get_option('admin_email')
            );

            $container->get(OrderNotifications::class)->orderCreated(
                $order,
                count($payload['items'] ?? []),
                is_string($buyerEmail) ? $buyerEmail : null,
                $staffEmail === '' ? null : $staffEmail,
                [
                    'store_name' => (string) get_bloginfo('name'),
                    'order_url' => Storefront::orderUrl($order),
                    'bank_details' => (string) get_option(Brand::OPTION_PREFIX . 'bank_details', ''),
                ]
            );
        });

        // §14.1: the customer hears about a parcel once it is on its way or has
        // arrived. Delivery is queued through the same outbox as the order
        // confirmation, never sent inside the staff request.
        add_action(Brand::hook('shipment/status_changed'), static function (array $payload) use ($container): void {
            $order = $payload['order'] ?? null;
            $shipment = $payload['shipment'] ?? null;

            if (!is_array($order) || !is_array($shipment)) {
                return;
            }

            $address = json_decode((string) ($order['shipping_address'] ?? ''), true);
            $buyerEmail = is_array($address) ? ($address['email'] ?? null) : null;

            if ((!is_string($buyerEmail) || $buyerEmail === '') && !empty($order['customer_id'])) {
                $customer = $container->get(CustomerRepository::class)->customer((int) $order['customer_id']);
                $buyerEmail = $customer['email'] ?? null;
            }

            $container->get(OrderNotifications::class)->shipmentUpdated(
                $order,
                $shipment,
                (string) ($payload['to'] ?? ''),
                is_string($buyerEmail) ? $buyerEmail : null,
                [
                    'store_name' => (string) get_bloginfo('name'),
                    'order_url' => Storefront::orderUrl($order),
                    'tracking_url' => Storefront::trackUrl($order),
                ]
            );
        });

        // §15.3: every state change a device cares about is appended to the
        // change feed. Devices pull stock, order and customer deltas; the
        // catalog itself is sent in full on each pull.
        $changeLog = $container->get(ChangeLog::class);

        add_action(Brand::hook('stock/moved'), static function (array $movement) use ($changeLog): void {
            $changeLog->record('stock', isset($movement['variant_id']) ? (int) $movement['variant_id'] : null, (string) ($movement['type'] ?? 'moved'), [
                'variant_id' => isset($movement['variant_id']) ? (int) $movement['variant_id'] : null,
                'location_id' => isset($movement['location_id']) ? (int) $movement['location_id'] : null,
                'on_hand_delta' => (int) ($movement['on_hand_delta'] ?? 0),
                'reserved_delta' => (int) ($movement['reserved_delta'] ?? 0),
            ]);
        });

        foreach ([
            'order/created' => 'created',
            'order/payment_recorded' => 'payment',
            'order/completed' => 'completed',
            'order/cancelled' => 'cancelled',
        ] as $hook => $action) {
            add_action(Brand::hook($hook), static function (array $payload) use ($changeLog, $action): void {
                $order = $payload['order'] ?? null;

                if (is_array($order) && isset($order['id'])) {
                    $changeLog->record('order', (int) $order['id'], $action, [
                        'number' => $order['number'] ?? null,
                        'status' => $order['status'] ?? null,
                        'payment_status' => $order['payment_status'] ?? null,
                    ]);
                }
            });
        }

        add_action(Brand::hook('customer/registered'), static function (array $payload) use ($changeLog): void {
            $customer = $payload['customer'] ?? null;

            if (is_array($customer) && isset($customer['id'])) {
                $changeLog->record('customer', (int) $customer['id'], 'registered', [
                    'name' => $customer['name'] ?? null,
                    'group_id' => $customer['group_id'] ?? null,
                ]);
            }
        });

        add_action(Brand::hook('customer/approved'), static function (array $payload) use ($changeLog): void {
            $customer = $payload['customer'] ?? null;

            if (is_array($customer) && isset($customer['id'])) {
                $changeLog->record('customer', (int) $customer['id'], 'approved', [
                    'name' => $customer['name'] ?? null,
                    'group_id' => $customer['group_id'] ?? null,
                ]);
            }
        });

        add_action(Brand::hook('catalog/product_created'), static function (array $payload) use ($changeLog): void {
            if (isset($payload['product_id'])) {
                $changeLog->record('catalog', (int) $payload['product_id'], 'created');
            }
        });

        // §15.4: a device that stops syncing raises one alert per device.
        add_action('storekeeper_check_devices', static function () use ($container): void {
            $minutes = (int) get_option(Brand::OPTION_PREFIX . 'device_stale_minutes', 10);

            foreach ($container->get(DeviceService::class)->stale($minutes) as $device) {
                $container->get(AlertService::class)->raise(
                    AlertService::DEVICE_STALE,
                    AlertService::WARNING,
                    'device',
                    (int) $device['id'],
                    [
                        'name' => $device['name'] ?? null,
                        'last_sync_at' => $device['last_sync_at'] ?? null,
                        'stale_after_minutes' => $minutes,
                    ]
                );
            }
        });

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
