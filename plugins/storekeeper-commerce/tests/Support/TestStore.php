<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Support;

use StoreKeeper\Commerce\Alerts\AlertService;
use StoreKeeper\Commerce\Cart\CartRepository;
use StoreKeeper\Commerce\Cart\CartService;
use StoreKeeper\Commerce\Catalog\CatalogRepository;
use StoreKeeper\Commerce\Catalog\CatalogService;
use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Audit\DatabaseAuditLogger;
use StoreKeeper\Commerce\Core\Database\PdoConnection;
use StoreKeeper\Commerce\Core\FrozenClock;
use StoreKeeper\Commerce\Core\Hooks\InMemoryHooks;
use StoreKeeper\Commerce\Core\Money;
use StoreKeeper\Commerce\Core\Uuid;
use StoreKeeper\Commerce\Checkout\CheckoutService;
use StoreKeeper\Commerce\Customers\CustomerRepository;
use StoreKeeper\Commerce\Customers\CustomerService;
use StoreKeeper\Commerce\Customers\CustomerSessionRepository;
use StoreKeeper\Commerce\Customers\OtpRepository;
use StoreKeeper\Commerce\Customers\StorefrontAuth;
use StoreKeeper\Commerce\Inventory\InventoryRepository;
use StoreKeeper\Commerce\Inventory\InventoryService;
use StoreKeeper\Commerce\Notifications\MessageLogRepository;
use StoreKeeper\Commerce\Notifications\MessageService;
use StoreKeeper\Commerce\Notifications\MessageTemplateRepository;
use StoreKeeper\Commerce\Notifications\OrderNotifications;
use StoreKeeper\Commerce\Storefront\StorefrontAccount;
use StoreKeeper\Commerce\Storefront\StorefrontRegistration;
use StoreKeeper\Commerce\Orders\OrderRepository;
use StoreKeeper\Commerce\Orders\OrderService;
use StoreKeeper\Commerce\Orders\ShipmentRepository;
use StoreKeeper\Commerce\Orders\ShipmentService;
use StoreKeeper\Commerce\Pricing\PricingRepository;
use StoreKeeper\Commerce\Pricing\PricingService;
use StoreKeeper\Commerce\Shipping\ShippingRepository;
use StoreKeeper\Commerce\Shipping\ShippingService;

/**
 * A fully wired store on a fresh test schema (engine only, no WordPress).
 */
final class TestStore
{
    public PdoConnection $db;

    public FrozenClock $clock;

    public InMemoryHooks $hooks;

    public DatabaseAuditLogger $audit;

    public AlertService $alerts;

    public PricingRepository $pricingRepository;

    public PricingService $pricing;

    public InventoryRepository $inventoryRepository;

    public InventoryService $inventory;

    public CatalogRepository $catalogRepository;

    public CatalogService $catalog;

    public CustomerRepository $customerRepository;

    public CustomerService $customers;

    public OtpRepository $otpRepository;

    public CustomerSessionRepository $sessionRepository;

    public InMemoryOtpSender $otpSender;

    public StorefrontAuth $auth;

    public OrderRepository $orderRepository;

    public OrderService $orders;

    public ShipmentRepository $shipmentRepository;

    public ShipmentService $shipments;

    public ShippingRepository $shippingRepository;

    public ShippingService $shipping;

    public CartRepository $cartRepository;

    public CartService $cart;

    public CheckoutService $checkout;

    public StorefrontRegistration $registration;

    public StorefrontAccount $account;

    public MessageTemplateRepository $templateRepository;

    public MessageLogRepository $messageLog;

    public InMemoryChannel $channel;

    public MessageService $messages;

    public OrderNotifications $orderNotifications;

    /** Flat delivery fee used by the test checkout; shipping tests rely on it. */
    public int $flatShippingKobo = 150000;

    private function __construct(PdoConnection $db)
    {
        $this->db = $db;
        $this->clock = new FrozenClock();
        $this->hooks = new InMemoryHooks();
        $this->audit = new DatabaseAuditLogger($db, $this->clock);
        $this->alerts = new AlertService($db, $this->clock);

        $this->pricingRepository = new PricingRepository($db, $this->clock);
        $this->pricing = new PricingService($this->pricingRepository, $this->clock, $this->audit);

        $this->inventoryRepository = new InventoryRepository($db, $this->clock);
        $this->inventory = new InventoryService(
            $db,
            $this->inventoryRepository,
            $this->hooks,
            $this->clock,
            $this->alerts
        );

        $this->catalogRepository = new CatalogRepository($db, $this->clock);
        $this->catalog = new CatalogService(
            $db,
            $this->catalogRepository,
            $this->inventory,
            $this->pricingRepository,
            $this->hooks,
            $this->audit,
            $this->clock
        );

        $this->customerRepository = new CustomerRepository($db, $this->clock);
        $this->customers = new CustomerService(
            $db,
            $this->customerRepository,
            $this->pricingRepository,
            $this->hooks,
            $this->audit,
            $this->clock
        );

        $this->otpRepository = new OtpRepository($db);
        $this->sessionRepository = new CustomerSessionRepository($db);
        $this->otpSender = new InMemoryOtpSender();
        $this->auth = new StorefrontAuth(
            $this->otpRepository,
            $this->sessionRepository,
            $this->customerRepository,
            $this->otpSender,
            $this->clock
        );

        $this->orderRepository = new OrderRepository($db, $this->clock);
        $this->orders = new OrderService(
            $db,
            $this->orderRepository,
            $this->catalogRepository,
            $this->pricing,
            $this->inventory,
            $this->hooks,
            $this->audit,
            $this->clock,
            $this->customerRepository,
            'TEST'
        );

        $this->shippingRepository = new ShippingRepository($db);
        $this->shipping = new ShippingService($this->shippingRepository, $this->audit);

        $this->shipmentRepository = new ShipmentRepository($db, $this->clock);
        $this->shipments = new ShipmentService(
            $db,
            $this->shipmentRepository,
            $this->orderRepository,
            $this->orders,
            $this->inventory,
            $this->hooks,
            $this->audit,
            $this->clock
        );

        $this->cartRepository = new CartRepository($db);
        $this->cart = new CartService(
            $this->cartRepository,
            $this->catalogRepository,
            $this->pricing,
            $this->inventoryRepository,
            $this->clock
        );
        $this->registration = new StorefrontRegistration($this->customerRepository, $this->customers);
        $this->account = new StorefrontAccount($this->orderRepository, $this->cart, $this->customerRepository);

        $this->templateRepository = new MessageTemplateRepository($db);
        $this->messageLog = new MessageLogRepository($db);
        $this->channel = new InMemoryChannel();
        $this->messages = new MessageService(
            $this->templateRepository,
            $this->messageLog,
            ['email' => $this->channel],
            $this->clock
        );
        $this->orderNotifications = new OrderNotifications($this->messages);

        $this->checkout = new CheckoutService(
            $this->cartRepository,
            $this->cart,
            $this->orders,
            $this->customerRepository,
            $this->flatShippingKobo,
            [],
            null,
            $this->shipping
        );
    }

    public static function create(): self
    {
        return new self(TestDatabase::fresh());
    }

    public static function connect(): self
    {
        return new self(TestDatabase::connection());
    }

    public function actor(): Actor
    {
        return Actor::staff(1);
    }

    /** @return array{retail:int,wholesale:int} */
    public function seedPriceLists(): array
    {
        return [
            'retail' => $this->pricingRepository->createList('Retail', 'NGN', true),
            'wholesale' => $this->pricingRepository->createList('Wholesale', 'NGN', false),
        ];
    }

    public function seedGroups(): void
    {
        $this->seedPriceLists();
        $this->customers->seedDefaultGroups();
    }

    /** @return array<string,mixed> */
    public function seedLocation(string $name = 'Main shop', bool $sellableOnline = true): array
    {
        $locationId = $this->inventoryRepository->createLocation([
            'uuid' => Uuid::v4(),
            'name' => $name,
            'type' => 'shop',
            'sellable_online' => $sellableOnline ? 1 : 0,
            'online_priority' => 1,
            'address' => null,
            'is_pickup_point' => 1,
            'is_active' => 1,
            'created_at' => $this->clock->nowString(),
        ]);

        return $this->inventoryRepository->location($locationId) ?? [];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array{product:array<string,mixed>,variant:array<string,mixed>}
     */
    public function seedProduct(int $locationId, int $priceKobo = 500000, int $stock = 20, array $overrides = []): array
    {
        $result = $this->catalog->createProduct([
            'name' => 'Gold hoop earrings ' . substr($this->clock->nowString(), -4),
            'status' => 'active',
            'price_kobo' => $priceKobo,
            'stock' => $stock,
            'location_id' => $locationId,
            ...$overrides,
        ], $this->actor());

        return ['product' => $result['product'], 'variant' => $result['variants'][0]];
    }

    public function setPrice(int $priceListId, int $variantId, int $amountKobo, int $minQty = 1, ?int $packUnitId = null): void
    {
        $this->pricing->setPrice($priceListId, $variantId, $packUnitId, $minQty, new Money($amountKobo), $this->actor());
    }

    /** @return array<string,mixed> */
    public function groupNamed(string $name): array
    {
        foreach ($this->customerRepository->groups() as $group) {
            if ($group['name'] === $name) {
                return $group;
            }
        }

        throw new \RuntimeException('Group not found: ' . $name);
    }

    /** @return array<string,mixed> */
    public function wholesaleCustomer(string $phone = '08031234567', ?string $email = 'ada@example.test'): array
    {
        $group = $this->groupNamed('Wholesale');

        return $this->customers->create([
            'name' => 'Ada Wholesale',
            'business_name' => 'Ada Fabrics',
            'phone' => $phone,
            'email' => $email,
            'group_id' => $group['id'],
        ], $this->actor(), 'storefront');
    }
}
