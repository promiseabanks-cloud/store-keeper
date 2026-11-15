<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Inventory\Exception\InsufficientStock;
use StoreKeeper\Commerce\Orders\Exception\InvalidTransition;
use StoreKeeper\Commerce\Orders\OrderStatus;
use StoreKeeper\Commerce\Orders\PaymentStatus;
use StoreKeeper\Commerce\Pricing\PriceSource;
use StoreKeeper\Commerce\Tests\Support\TestStore;

final class OrderServiceTest extends TestCase
{
    private TestStore $store;

    private int $locationId;

    private int $variantId;

    private int $wholesaleListId;

    protected function setUp(): void
    {
        $this->store = TestStore::create();
        $this->store->seedGroups();
        $this->locationId = (int) $this->store->seedLocation()['id'];
        $this->variantId = (int) $this->store->seedProduct($this->locationId, 500000, 20)['variant']['id'];
        $this->wholesaleListId = (int) $this->store->groupNamed('Wholesale')['price_list_id'];
    }

    public function testCreatingAnOrderReservesStockAndSetsAHold(): void
    {
        $result = $this->createOrder(['lines' => [['variant_id' => $this->variantId, 'qty' => 2]]]);

        $order = $result['order'];

        self::assertSame(OrderStatus::OPEN, $order['status']);
        self::assertSame(PaymentStatus::UNPAID, $order['payment_status']);
        self::assertSame(1000000, (int) $order['total_kobo']);
        self::assertGreaterThan(0, (int) $order['customer_group_id']);
        self::assertNotNull($order['hold_expires_at'], 'A bank transfer order holds stock for 24h');
        self::assertMatchesRegularExpression('/^TEST-\d{5}$/', (string) $order['number']);
        self::assertSame(2, (int) $this->store->inventory->level($this->variantId, $this->locationId)['reserved']);
        self::assertTrue($this->store->hooks->hasFired('order/created'));
    }

    public function testTheSameIdempotencyKeyReturnsTheSameOrderAndDoesNotDoubleReserve(): void
    {
        $first = $this->createOrder([
            'idempotency_key' => 'same-key',
            'lines' => [['variant_id' => $this->variantId, 'qty' => 3]],
        ]);
        $second = $this->createOrder([
            'idempotency_key' => 'same-key',
            'lines' => [['variant_id' => $this->variantId, 'qty' => 3]],
        ]);

        self::assertFalse($first['replayed']);
        self::assertTrue($second['replayed']);
        self::assertSame((int) $first['order']['id'], (int) $second['order']['id']);
        self::assertSame(3, (int) $this->store->inventory->level($this->variantId, $this->locationId)['reserved']);
        self::assertSame(1, (int) $this->store->db->scalar(
            sprintf('SELECT COUNT(*) FROM %sorders', $this->store->db->prefix())
        ));
    }

    public function testCancellingAnOrderReleasesItsReservation(): void
    {
        $result = $this->createOrder(['lines' => [['variant_id' => $this->variantId, 'qty' => 4]]]);

        $cancelled = $this->store->orders->cancel((int) $result['order']['id'], 'Customer changed their mind', $this->store->actor());

        self::assertSame(OrderStatus::CANCELLED, $cancelled['status']);
        self::assertSame(0, (int) $this->store->inventory->level($this->variantId, $this->locationId)['reserved']);
        self::assertSame([], $this->store->inventory->verify());
        self::assertTrue($this->store->hooks->hasFired('order/cancelled'));
    }

    public function testCancellingACompletedOrderIsForbidden(): void
    {
        $result = $this->store->orders->create([
            'channel' => 'counter',
            'handed_over_now' => true,
            'payment_method' => 'cash',
            'idempotency_key' => 'counter-sale-1',
            'lines' => [['variant_id' => $this->variantId, 'qty' => 1]],
        ], $this->store->actor());

        self::assertSame(OrderStatus::COMPLETED, $result['order']['status']);
        self::assertSame(PaymentStatus::PAID, $result['order']['payment_status']);

        $this->expectException(InvalidTransition::class);

        $this->store->orders->cancel((int) $result['order']['id'], 'Too late', $this->store->actor());
    }

    public function testCounterHandoverCommitsStockAndCompletesTheOrder(): void
    {
        $result = $this->store->orders->create([
            'channel' => 'counter',
            'handed_over_now' => true,
            'payment_method' => 'cash',
            'idempotency_key' => 'counter-sale-2',
            'lines' => [['variant_id' => $this->variantId, 'qty' => 2]],
        ], $this->store->actor());

        self::assertSame(18, (int) $this->store->inventory->level($this->variantId, $this->locationId)['on_hand']);
        self::assertSame(0, (int) $this->store->inventory->level($this->variantId, $this->locationId)['reserved']);
        self::assertSame('fulfilled', $result['order']['fulfilment_status']);
        self::assertCount(1, $this->store->orders->payments((int) $result['order']['id']));
        self::assertTrue($this->store->hooks->hasFired('order/completed'));
    }

    public function testHandoverCommitsReservationsAndCompletesWhenPaid(): void
    {
        $result = $this->createOrder(['lines' => [['variant_id' => $this->variantId, 'qty' => 2]]]);
        $orderId = (int) $result['order']['id'];

        // Record a confirmed payment, then hand the goods over (packed/collected).
        $order = $this->store->orders->find($orderId);
        $now = $this->store->clock->nowString();
        $this->store->db->insert($this->store->db->prefix() . 'payments', [
            'uuid' => \StoreKeeper\Commerce\Core\Uuid::v4(),
            'order_id' => $orderId,
            'method' => 'bank_transfer',
            'status' => 'succeeded',
            'amount_kobo' => (int) $order['total_kobo'],
            'confirmed_by' => 1,
            'confirmed_at' => $now,
            'idempotency_key' => 'payment-' . $orderId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $updated = $this->store->orders->handover($orderId, $this->store->actor());

        self::assertSame(18, (int) $this->store->inventory->level($this->variantId, $this->locationId)['on_hand']);
        self::assertSame(0, (int) $this->store->inventory->level($this->variantId, $this->locationId)['reserved']);
        self::assertSame('fulfilled', $updated['fulfilment_status']);
        self::assertSame(OrderStatus::COMPLETED, $updated['status']);
    }

    public function testInsufficientStockRollsTheWholeOrderBack(): void
    {
        try {
            $this->createOrder(['lines' => [['variant_id' => $this->variantId, 'qty' => 999]]]);
            self::fail('Expected InsufficientStock.');
        } catch (InsufficientStock) {
            self::assertSame(0, (int) $this->store->db->scalar(
                sprintf('SELECT COUNT(*) FROM %sorders', $this->store->db->prefix())
            ));
            self::assertSame(0, (int) $this->store->inventory->level($this->variantId, $this->locationId)['reserved']);
            self::assertSame([], $this->store->inventory->verify());
        }
    }

    public function testDraftOrdersDoNotReserveStock(): void
    {
        $result = $this->createOrder([
            'draft' => true,
            'lines' => [['variant_id' => $this->variantId, 'qty' => 5]],
        ]);

        self::assertSame(OrderStatus::DRAFT, $result['order']['status']);
        self::assertSame(0, (int) $this->store->inventory->level($this->variantId, $this->locationId)['reserved']);
        self::assertNull($result['order']['hold_expires_at']);
    }

    public function testApprovedWholesalerIsChargedTheWholesalePriceEvenBelowTheTier(): void
    {
        $this->store->setPrice($this->wholesaleListId, $this->variantId, 400000, 12);
        $customer = $this->store->wholesaleCustomer();

        $result = $this->store->orders->create([
            'channel' => 'whatsapp',
            'customer' => $customer,
            'idempotency_key' => 'wholesale-order-1',
            'lines' => [['variant_id' => $this->variantId, 'qty' => 3]],
        ], $this->store->actor());

        $item = $result['items'][0];

        self::assertSame(400000, (int) $item['unit_price_kobo']);
        self::assertSame(500000, (int) $item['list_price_kobo']);
        self::assertSame(PriceSource::GROUP_TIER, $item['price_source']);
        self::assertSame(1200000, (int) $result['order']['total_kobo']);
    }

    public function testGuestsAreChargedRetail(): void
    {
        $this->store->setPrice($this->wholesaleListId, $this->variantId, 400000, 1);

        $result = $this->createOrder(['lines' => [['variant_id' => $this->variantId, 'qty' => 1]]]);

        self::assertSame(500000, (int) $result['items'][0]['unit_price_kobo']);
        self::assertSame(PriceSource::BASE, $result['items'][0]['price_source']);
    }

    public function testManualPricesRequirePermission(): void
    {
        $this->expectException(ValidationFailed::class);

        $this->createOrder([
            'lines' => [['variant_id' => $this->variantId, 'qty' => 1, 'unit_price_kobo' => 100000]],
        ]);
    }

    public function testManualPriceIsHonouredWhenAllowed(): void
    {
        $result = $this->createOrder([
            'allow_manual_price' => true,
            'lines' => [['variant_id' => $this->variantId, 'qty' => 1, 'unit_price_kobo' => 450000]],
        ]);

        self::assertSame(450000, (int) $result['items'][0]['unit_price_kobo']);
        self::assertSame(PriceSource::MANUAL, $result['items'][0]['price_source']);
    }

    public function testDiscountCannotExceedSubtotal(): void
    {
        $this->expectException(ValidationFailed::class);

        $this->createOrder([
            'discount_kobo' => 99999999,
            'lines' => [['variant_id' => $this->variantId, 'qty' => 1]],
        ]);
    }

    public function testPayOnDeliveryOrdersAreMarkedAsSuch(): void
    {
        $result = $this->createOrder([
            'payment_method' => 'pay_on_delivery',
            'lines' => [['variant_id' => $this->variantId, 'qty' => 1]],
        ]);

        self::assertSame(PaymentStatus::PAY_ON_DELIVERY, $result['order']['payment_status']);
        self::assertNull($result['order']['hold_expires_at']);
    }

    public function testOrderTotalsIncludeShippingAndFee(): void
    {
        $result = $this->createOrder([
            'shipping_kobo' => 250000,
            'fee_kobo' => 15000,
            'discount_kobo' => 100000,
            'lines' => [['variant_id' => $this->variantId, 'qty' => 2]],
        ]);

        // 2 × ₦5,000 = ₦10,000 − ₦1,000 + ₦2,500 + ₦150
        self::assertSame(1165000, (int) $result['order']['total_kobo']);
        self::assertSame(1000000, (int) $result['order']['subtotal_kobo']);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array{order:array<string,mixed>,items:list<array<string,mixed>>,replayed:bool}
     */
    private function createOrder(array $overrides = []): array
    {
        static $counter = 0;
        $counter++;

        return $this->store->orders->create([
            'channel' => 'whatsapp',
            'idempotency_key' => 'order-key-' . $counter,
            'payment_method' => 'bank_transfer',
            'lines' => [],
            ...$overrides,
        ], $this->store->actor());
    }
}
