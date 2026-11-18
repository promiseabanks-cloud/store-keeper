<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Uuid;
use StoreKeeper\Commerce\Orders\FulfilmentStatus;
use StoreKeeper\Commerce\Orders\OrderStatus;
use StoreKeeper\Commerce\Orders\PaymentStatus;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * A registered device sells from its own location, records the cashier, and a
 * counter return puts goods back without ever exceeding the original line
 * (§15.1, §15.3).
 */
final class PosTest extends TestCase
{
    private TestStore $store;

    private int $locationId;

    private int $variantId;

    private string $sku;

    /** @var array{device:array<string,mixed>,token:string} */
    private array $registered;

    protected function setUp(): void
    {
        $this->store = TestStore::create();
        $this->store->seedGroups();
        $this->locationId = (int) $this->store->seedLocation()['id'];
        $product = $this->store->seedProduct($this->locationId, 500000, 20);
        $this->variantId = (int) $product['variant']['id'];
        $this->sku = (string) $product['variant']['sku'];
        $this->registered = $this->store->deviceService->register(
            ['name' => 'Front counter', 'location_id' => $this->locationId],
            Actor::staff(1)
        );
    }

    public function testADeviceIsRegisteredOnceAndCanBeRevoked(): void
    {
        self::assertSame('Front counter', $this->registered['device']['name']);
        self::assertStringStartsWith('skd_', $this->registered['token']);
        self::assertNotSame($this->registered['token'], (string) $this->registered['device']['token_hash']);

        $authenticated = $this->store->deviceService->authenticate($this->registered['token']);
        self::assertNotNull($authenticated);
        self::assertSame((int) $this->registered['device']['id'], (int) $authenticated['id']);

        $this->store->deviceService->revoke((string) $this->registered['device']['uuid'], Actor::staff(1));

        self::assertNull($this->store->deviceService->authenticate($this->registered['token']));
    }

    public function testACashierPinIsHashedAndLocksAfterFiveWrongTries(): void
    {
        $this->store->deviceService->setPin(7, '4821', Actor::staff(1));

        self::assertTrue($this->store->deviceService->hasPin(7));
        self::assertTrue($this->store->deviceService->verifyPin(7, '4821')['ok']);

        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($this->store->deviceService->verifyPin(7, '0000')['ok']);
        }

        $locked = $this->store->deviceService->verifyPin(7, '4821');
        self::assertFalse($locked['ok']);
        self::assertSame('locked', $locked['reason']);
    }

    public function testACounterSaleTakesStockAndCompletesTheOrder(): void
    {
        $result = $this->sale(2, 1000000);

        self::assertFalse($result['duplicate']);
        self::assertSame(OrderStatus::COMPLETED, $result['order']['status']);
        self::assertSame(PaymentStatus::PAID, $result['order']['payment_status']);
        self::assertSame(FulfilmentStatus::FULFILLED, $result['order']['fulfilment_status']);
        self::assertSame(1000000, (int) $result['order']['total_kobo']);
        self::assertSame(18, (int) $this->level()['on_hand'], 'Two pieces left the shelf');
        self::assertSame([], $this->store->inventory->verify());

        $posSale = $this->store->db->first(
            sprintf('SELECT * FROM %spos_sales WHERE order_id = ?', $this->store->db->prefix()),
            [(int) $result['order']['id']]
        );
        self::assertNotNull($posSale);
        self::assertSame(9, (int) $posSale['cashier_user_id']);
        self::assertSame((int) $this->registered['device']['id'], (int) $posSale['device_id']);
    }

    public function testASaleAboveStockIsAcceptedAndWarnsAboutTheOversell(): void
    {
        $result = $this->sale(25, 12500000);

        self::assertSame(-5, (int) $this->level()['on_hand']);
        self::assertContains('oversell:' . $this->sku, $result['warnings']);
        self::assertNotEmpty($this->store->alerts->open());
    }

    public function testASaleCanBeSplitAcrossCashAndTransfer(): void
    {
        $result = $this->store->counter->sale([
            'client_uuid' => Uuid::v4(),
            'cashier_user_id' => 9,
            'lines' => [['variant_id' => $this->variantId, 'qty' => 2, 'unit_price_kobo' => 500000]],
            'payments' => [
                ['method' => 'cash', 'amount_kobo' => 400000],
                ['method' => 'bank_transfer', 'amount_kobo' => 600000, 'reference' => 'TRX-9'],
            ],
        ], $this->registered['device'], Actor::staff(9));

        self::assertSame(PaymentStatus::PAID, $result['order']['payment_status']);
        self::assertCount(2, $this->store->orders->payments((int) $result['order']['id']));
    }

    public function testTheSameClientUuidNeverCreatesASecondSale(): void
    {
        $uuid = Uuid::v4();
        $device = $this->registered['device'];

        $first = $this->store->counter->sale([
            'client_uuid' => $uuid,
            'cashier_user_id' => 9,
            'lines' => [['variant_id' => $this->variantId, 'qty' => 1, 'unit_price_kobo' => 500000]],
            'payments' => [['method' => 'cash', 'amount_kobo' => 500000]],
        ], $device, Actor::staff(9));

        $second = $this->store->counter->sale([
            'client_uuid' => $uuid,
            'cashier_user_id' => 9,
            'lines' => [['variant_id' => $this->variantId, 'qty' => 1, 'unit_price_kobo' => 500000]],
            'payments' => [['method' => 'cash', 'amount_kobo' => 500000]],
        ], $device, Actor::staff(9));

        self::assertTrue($second['duplicate']);
        self::assertSame((int) $first['order']['id'], (int) $second['order']['id']);
        self::assertSame(19, (int) $this->level()['on_hand']);
    }

    public function testAParkedSaleMovesNoStockAndCanBeResumed(): void
    {
        $held = $this->store->counter->hold([
            'client_uuid' => Uuid::v4(),
            'cashier_user_id' => 9,
            'lines' => [['variant_id' => $this->variantId, 'qty' => 3, 'unit_price_kobo' => 500000]],
        ], $this->registered['device'], Actor::staff(9));

        self::assertSame(OrderStatus::DRAFT, $held['order']['status']);
        self::assertSame(20, (int) $this->level()['on_hand']);
        self::assertSame(0, (int) $this->level()['reserved']);

        $list = $this->store->counter->held((int) $this->registered['device']['id']);
        self::assertCount(1, $list);
        self::assertCount(1, $list[0]['items']);

        $this->store->counter->cancelHeld((int) $held['order']['id'], $this->registered['device'], Actor::staff(9));
        self::assertSame([], $this->store->counter->held((int) $this->registered['device']['id']));
    }

    public function testACashUpComparesCountedCashWithWhatTheDeviceTook(): void
    {
        $this->sale(2, 1000000);

        $cashUp = $this->store->counter->cashUp([
            'cashier_user_id' => 9,
            'business_date' => $this->store->clock->now()->format('Y-m-d'),
            'opening_float_kobo' => 50000,
            'counted_cash_kobo' => 1050000,
            'note' => 'Evening count',
        ], $this->registered['device'], Actor::staff(9));

        self::assertSame(1050000, (int) $cashUp['expected_cash_kobo'], 'Opening float plus the cash sale');
        self::assertSame(0, (int) $cashUp['difference_kobo']);
    }

    public function testACounterReturnRestocksAndRefusesToExceedTheLine(): void
    {
        $sale = $this->sale(2, 1000000);
        $item = $this->store->orders->items((int) $sale['order']['id'])[0];
        $uuid = Uuid::v4();

        $returned = $this->store->counter->counterReturn([
            'client_uuid' => $uuid,
            'order_number' => $sale['order']['number'],
            'cashier_user_id' => 9,
            'reason' => 'Wrong size',
            'restock' => true,
            'lines' => [['order_item_id' => (int) $item['id'], 'qty_pieces' => 1]],
        ], $this->registered['device'], Actor::staff(9));

        self::assertFalse($returned['duplicate']);
        self::assertGreaterThan(0, (int) $returned['refund']['amount_kobo']);
        self::assertSame(19, (int) $this->level()['on_hand'], 'One piece came back');
        self::assertSame(PaymentStatus::PARTIALLY_REFUNDED, $returned['order']['payment_status']);

        $again = $this->store->counter->counterReturn([
            'client_uuid' => $uuid,
            'order_number' => $sale['order']['number'],
            'cashier_user_id' => 9,
            'reason' => 'Wrong size',
            'restock' => true,
            'lines' => [['order_item_id' => (int) $item['id'], 'qty_pieces' => 1]],
        ], $this->registered['device'], Actor::staff(9));

        self::assertTrue($again['duplicate']);
        self::assertSame(19, (int) $this->level()['on_hand'], 'A repeated return must not restock twice');

        $this->expectException(ValidationFailed::class);

        $this->store->counter->counterReturn([
            'client_uuid' => Uuid::v4(),
            'order_number' => $sale['order']['number'],
            'cashier_user_id' => 9,
            'reason' => 'Another return',
            'lines' => [['order_item_id' => (int) $item['id'], 'qty_pieces' => 2]],
        ], $this->registered['device'], Actor::staff(9));
    }

    public function testAnOfflineQuotedPriceIsHonoured(): void
    {
        $result = $this->store->counter->sale([
            'client_uuid' => Uuid::v4(),
            'cashier_user_id' => 9,
            'lines' => [['variant_id' => $this->variantId, 'qty' => 1, 'unit_price_kobo' => 450000]],
            'payments' => [['method' => 'cash', 'amount_kobo' => 450000]],
        ], $this->registered['device'], Actor::staff(9));

        self::assertSame(450000, (int) $result['order']['subtotal_kobo']);
        self::assertSame('450000', (string) $this->store->orders->items((int) $result['order']['id'])[0]['unit_price_kobo']);
    }

    /** @return array<string,mixed> */
    private function sale(int $qty, int $amountKobo): array
    {
        return $this->store->counter->sale([
            'client_uuid' => Uuid::v4(),
            'cashier_user_id' => 9,
            'lines' => [['variant_id' => $this->variantId, 'qty' => $qty, 'unit_price_kobo' => intdiv($amountKobo, $qty)]],
            'payments' => [['method' => 'cash', 'amount_kobo' => $amountKobo]],
        ], $this->registered['device'], Actor::staff(9));
    }

    /** @return array<string,mixed> */
    private function level(): array
    {
        return $this->store->inventory->level($this->variantId, $this->locationId) ?? [];
    }
}
