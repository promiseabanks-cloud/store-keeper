<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Orders\OrderStatus;
use StoreKeeper\Commerce\Orders\PaymentStatus;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * Manual payments against an order (§12.2): a payment changes money, never
 * stock, and the order only completes once it is both paid and handed over.
 */
final class OrderPaymentTest extends TestCase
{
    private TestStore $store;

    private int $locationId;

    private int $variantId;

    protected function setUp(): void
    {
        $this->store = TestStore::create();
        $this->store->seedGroups();
        $this->locationId = (int) $this->store->seedLocation()['id'];
        $this->variantId = (int) $this->store->seedProduct($this->locationId, 500000, 20)['variant']['id'];
    }

    public function testFullPaymentMarksTheOrderPaidAndKeepsStockReserved(): void
    {
        $order = $this->openOrder(2);
        $orderId = (int) $order['id'];

        $paid = $this->store->orders->recordPayment($orderId, ['method' => 'bank_transfer'], $this->store->actor());

        self::assertSame(PaymentStatus::PAID, $paid['payment_status']);
        self::assertSame(1000000, (int) $paid['paid_kobo']);
        self::assertSame(OrderStatus::OPEN, $paid['status'], 'Payment alone never completes an order');
        self::assertSame(2, (int) $this->store->inventory->level($this->variantId, $this->locationId)['reserved']);
        // A reservation holds stock without removing it; on_hand only moves on
        // handover/counter sale.
        self::assertSame(20, (int) $this->store->inventory->level($this->variantId, $this->locationId)['on_hand']);
        self::assertTrue($this->store->hooks->hasFired('order/payment_recorded'));
        self::assertSame([], $this->store->inventory->verify());
    }

    public function testAPartialPaymentIsTrackedAndOverpaymentIsRejected(): void
    {
        $order = $this->openOrder(2);
        $orderId = (int) $order['id'];

        $partial = $this->store->orders->recordPayment($orderId, [
            'method' => 'bank_transfer',
            'amount_kobo' => 400000,
        ], $this->store->actor());

        self::assertSame(PaymentStatus::PARTIALLY_PAID, $partial['payment_status']);
        self::assertSame(400000, (int) $partial['paid_kobo']);

        $this->expectException(ValidationFailed::class);

        $this->store->orders->recordPayment($orderId, [
            'method' => 'bank_transfer',
            'amount_kobo' => 900000,
        ], $this->store->actor());
    }

    public function testAnOrderReservesFromTheLocationThatHoldsTheStock(): void
    {
        $emptyShop = (int) $this->store->seedLocation('Empty shop')['id'];

        $result = $this->store->orders->create([
            'channel' => 'whatsapp',
            'payment_method' => 'bank_transfer',
            'location_id' => $emptyShop,
            'idempotency_key' => 'location-fallback',
            'lines' => [['variant_id' => $this->variantId, 'qty' => 2]],
        ], $this->store->actor());

        self::assertSame(2, (int) $this->store->inventory->level($this->variantId, $this->locationId)['reserved']);
        self::assertNull($this->store->inventory->level($this->variantId, $emptyShop), 'No stock level is created at the empty shop');
        self::assertSame((int) $this->locationId, (int) $result['items'][0]['location_id']);
    }

    public function testACancelledOrderRefusesPayment(): void
    {
        $order = $this->openOrder(1);
        $orderId = (int) $order['id'];
        $this->store->orders->cancel($orderId, 'Customer changed their mind', $this->store->actor());

        $this->expectException(ValidationFailed::class);

        $this->store->orders->recordPayment($orderId, ['method' => 'cash'], $this->store->actor());
    }

    /** @return array<string,mixed> */
    private function openOrder(int $qty): array
    {
        static $counter = 0;
        $counter++;

        $result = $this->store->orders->create([
            'channel' => 'whatsapp',
            'payment_method' => 'bank_transfer',
            'idempotency_key' => 'payment-test-' . $counter,
            'lines' => [['variant_id' => $this->variantId, 'qty' => $qty]],
        ], $this->store->actor());

        return $result['order'];
    }
}
