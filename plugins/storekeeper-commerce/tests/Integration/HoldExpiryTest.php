<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Orders\OrderStatus;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * §9.5: an unpaid order's hold runs out, the order is cancelled and the stock it
 * was sitting on goes back on sale. Before this, holds were written and never read.
 */
final class HoldExpiryTest extends TestCase
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

    public function testAnExpiredHoldIsCancelledAndItsStockReleased(): void
    {
        $order = $this->order(['hold_minutes' => 60]);
        self::assertSame(3, (int) $this->store->inventory->level($this->variantId, $this->locationId)['reserved']);

        $this->store->clock->advance('61 minutes');
        $result = $this->store->orders->expireHolds();

        self::assertSame([$order['number']], $result['expired']);
        self::assertSame([], $result['failed']);

        $updated = $this->store->orders->find((int) $order['id']);
        self::assertSame(OrderStatus::CANCELLED, $updated['status']);
        self::assertSame('Payment hold expired', $updated['cancel_reason']);
        self::assertSame(0, (int) $this->store->inventory->level($this->variantId, $this->locationId)['reserved']);
        self::assertSame([], $this->store->inventory->verify());
        self::assertTrue($this->store->hooks->hasFired('order/cancelled'));
    }

    public function testAHoldThatHasNotRunOutIsLeftAlone(): void
    {
        $order = $this->order(['hold_minutes' => 60]);

        $this->store->clock->advance('59 minutes');
        $result = $this->store->orders->expireHolds();

        self::assertSame([], $result['expired']);
        self::assertSame(OrderStatus::OPEN, $this->store->orders->find((int) $order['id'])['status']);
        self::assertSame(3, (int) $this->store->inventory->level($this->variantId, $this->locationId)['reserved']);
    }

    public function testAPaidOrHandedOverOrderIsNeverExpired(): void
    {
        $counter = $this->store->orders->create([
            'channel' => 'counter',
            'handed_over_now' => true,
            'payment_method' => 'cash',
            'idempotency_key' => 'counter-expiry',
            'lines' => [['variant_id' => $this->variantId, 'qty' => 1]],
        ], $this->store->actor());

        self::assertSame(OrderStatus::COMPLETED, $counter['order']['status']);

        $this->store->clock->advance('3 days');
        $result = $this->store->orders->expireHolds();

        self::assertSame([], $result['expired']);
        self::assertSame(OrderStatus::COMPLETED, $this->store->orders->find((int) $counter['order']['id'])['status']);
    }

    public function testAnOrderWithoutAHoldIsNotTouched(): void
    {
        $order = $this->order(['hold_minutes' => null]);
        self::assertNull($order['hold_expires_at']);

        $this->store->clock->advance('30 days');

        self::assertSame([], $this->store->orders->expireHolds()['expired']);
        self::assertSame(OrderStatus::OPEN, $this->store->orders->find((int) $order['id'])['status']);
    }

    public function testASecondRunDoesNothing(): void
    {
        $this->order(['hold_minutes' => 1]);
        $this->store->clock->advance('2 minutes');

        self::assertCount(1, $this->store->orders->expireHolds()['expired']);
        self::assertSame([], $this->store->orders->expireHolds()['expired']);
    }

    /** @param array<string,mixed> $overrides */
    private function order(array $overrides): array
    {
        $result = $this->store->orders->create([
            'channel' => 'web',
            'payment_method' => 'bank_transfer',
            'idempotency_key' => 'hold-' . uniqid(),
            'lines' => [['variant_id' => $this->variantId, 'qty' => 3]],
            ...$overrides,
        ], $this->store->actor());

        return $result['order'];
    }
}
