<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Uuid;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * The offline protocol (§15.3): pull returns a snapshot and a cursor, push
 * applies operations once, and a resent batch returns the stored result.
 */
final class SyncTest extends TestCase
{
    private TestStore $store;

    private int $locationId;

    private int $variantId;

    /** @var array{device:array<string,mixed>,token:string} */
    private array $registered;

    protected function setUp(): void
    {
        $this->store = TestStore::create();
        $this->store->seedGroups();
        $this->locationId = (int) $this->store->seedLocation()['id'];
        $this->variantId = (int) $this->store->seedProduct($this->locationId, 500000, 10)['variant']['id'];
        $this->registered = $this->store->deviceService->register(
            ['name' => 'Shop tablet', 'location_id' => $this->locationId],
            Actor::staff(1)
        );
    }

    public function testPullSendsTheCatalogSnapshotAndACursor(): void
    {
        $pull = $this->store->sync->pull($this->registered['device'], null, ['store_name' => 'Test store']);

        self::assertTrue($pull['full_resync_required']);
        self::assertNotEmpty($pull['catalog']);
        self::assertSame($this->variantId, (int) $pull['catalog'][0]['variant_id']);
        self::assertSame($this->locationId, (int) $pull['settings']['location_id']);
        self::assertSame('Test store', $pull['settings']['store_name']);
        self::assertContainsOnly('int', array_map('intval', array_column($pull['stock_levels'], 'on_hand')));
        self::assertSame(10, (int) $pull['stock_levels'][0]['on_hand']);
        self::assertSame('0', $pull['cursor']);
    }

    public function testPullSendsOnlyChangesAfterACursor(): void
    {
        $this->store->changeLog->record('stock', $this->variantId, 'counter_sale', ['variant_id' => $this->variantId]);
        $this->store->changeLog->record('stock', 999, 'counter_sale', ['variant_id' => 999]);

        $pull = $this->store->sync->pull($this->registered['device'], '0', []);

        self::assertTrue($pull['full_resync_required'], 'There is no valid cursor yet');

        $cursor = (string) $this->store->changeLog->latestId();
        $this->store->changeLog->record('stock', $this->variantId, 'counter_sale', ['variant_id' => $this->variantId]);
        $this->store->changeLog->record('stock', 999, 'counter_sale', ['variant_id' => 999]);

        $incremental = $this->store->sync->pull($this->registered['device'], $cursor, []);

        self::assertFalse($incremental['full_resync_required']);
        self::assertSame(1, count($incremental['stock_levels']));
    }

    public function testPushAppliesOneSaleOnlyOncePerBatch(): void
    {
        $operation = $this->saleOperation(2);
        $batch = ['batch_uuid' => Uuid::v4(), 'operations' => [$operation]];

        $first = $this->store->sync->push($this->registered['device'], $batch);
        self::assertFalse($first['replayed']);
        self::assertSame('applied', $first['results'][0]['status']);
        self::assertNotEmpty($first['results'][0]['order_number']);
        self::assertSame(8, (int) $this->level()['on_hand']);

        $again = $this->store->sync->push($this->registered['device'], $batch);
        self::assertTrue($again['replayed']);
        self::assertSame($first['results'], $again['results']);
        self::assertSame(8, (int) $this->level()['on_hand'], 'A resent batch must not sell twice');

        $sameClient = $this->store->sync->push($this->registered['device'], [
            'batch_uuid' => Uuid::v4(),
            'operations' => [$operation],
        ]);
        self::assertSame('duplicate', $sameClient['results'][0]['status']);
        self::assertSame(8, (int) $this->level()['on_hand'], 'A replayed client UUID must not sell twice');
    }

    public function testOneBadOperationDoesNotBlockTheRest(): void
    {
        $result = $this->store->sync->push($this->registered['device'], [
            'batch_uuid' => Uuid::v4(),
            'operations' => [
                ['op' => 'counter_sale', 'client_uuid' => Uuid::v4(), 'lines' => [], 'payments' => []],
                $this->saleOperation(1),
            ],
        ]);

        self::assertSame('rejected', $result['results'][0]['status']);
        self::assertNotNull($result['results'][0]['error']);
        self::assertSame('applied', $result['results'][1]['status']);
        self::assertSame(9, (int) $this->level()['on_hand']);
    }

    public function testPushCanCreateACustomerAndReturnGoods(): void
    {
        $sale = $this->saleOperation(2);
        $created = $this->store->sync->push($this->registered['device'], [
            'batch_uuid' => Uuid::v4(),
            'operations' => [
                [
                    'op' => 'customer_create',
                    'client_uuid' => Uuid::v4(),
                    'name' => 'Walk-in Bola',
                    'phone' => '08035550123',
                ],
            ],
        ]);

        self::assertSame('applied', $created['results'][0]['status']);
        self::assertNotNull($this->store->customerRepository->customerByPhone('+2348035550123'));

        $sold = $this->store->sync->push($this->registered['device'], [
            'batch_uuid' => Uuid::v4(),
            'operations' => [$sale],
        ]);
        $orderNumber = $sold['results'][0]['order_number'];

        $order = $this->store->orderRepository->orderByNumber($orderNumber);
        self::assertNotNull($order);
        $item = $this->store->orders->items((int) $order['id'])[0];

        $returned = $this->store->sync->push($this->registered['device'], [
            'batch_uuid' => Uuid::v4(),
            'operations' => [[
                'op' => 'counter_return',
                'client_uuid' => Uuid::v4(),
                'order_number' => $orderNumber,
                'cashier_user_id' => 9,
                'reason' => 'Changed mind',
                'restock' => true,
                'lines' => [['order_item_id' => (int) $item['id'], 'qty_pieces' => 1]],
            ]],
        ]);

        self::assertSame('applied', $returned['results'][0]['status']);
        self::assertSame(9, (int) $this->level()['on_hand']);
    }

    public function testPushRecordsTheDeviceSyncTime(): void
    {
        self::assertNull($this->registered['device']['last_sync_at']);

        $this->store->sync->push($this->registered['device'], [
            'batch_uuid' => Uuid::v4(),
            'operations' => [$this->saleOperation(1)],
        ]);

        $device = $this->store->deviceRepository->find((int) $this->registered['device']['id']);
        self::assertNotNull($device['last_sync_at']);
    }

    /** @return array<string,mixed> */
    private function saleOperation(int $qty): array
    {
        return [
            'op' => 'counter_sale',
            'client_uuid' => Uuid::v4(),
            'client_created_at' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'cashier_user_id' => 9,
            'customer_id' => null,
            'lines' => [[
                'variant_id' => $this->variantId,
                'qty' => $qty,
                'unit_price_kobo' => 500000,
            ]],
            'payments' => [['method' => 'cash', 'amount_kobo' => 500000 * $qty]],
        ];
    }

    /** @return array<string,mixed> */
    private function level(): array
    {
        return $this->store->inventory->level($this->variantId, $this->locationId) ?? [];
    }
}
