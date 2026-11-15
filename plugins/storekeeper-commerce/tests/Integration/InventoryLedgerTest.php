<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Alerts\AlertService;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Inventory\Exception\InsufficientStock;
use StoreKeeper\Commerce\Inventory\MovementType;
use StoreKeeper\Commerce\Tests\Support\TestStore;

final class InventoryLedgerTest extends TestCase
{
    private TestStore $store;

    private int $locationId;

    private int $variantId;

    protected function setUp(): void
    {
        $this->store = TestStore::create();
        $this->store->seedGroups();
        $this->locationId = (int) $this->store->seedLocation()['id'];
        $product = $this->store->seedProduct($this->locationId, 500000, 20);
        $this->variantId = (int) $product['variant']['id'];
    }

    public function testOpeningStockCreatesALevelAndAnAppendOnlyMovement(): void
    {
        $level = $this->store->inventory->level($this->variantId, $this->locationId);

        self::assertSame(20, (int) $level['on_hand']);
        self::assertSame(0, (int) $level['reserved']);

        $movements = $this->store->inventory->movements($this->variantId, $this->locationId);
        self::assertCount(1, $movements);
        self::assertSame(MovementType::OPENING, $movements[0]['type']);
        self::assertSame(20, (int) $movements[0]['on_hand_delta']);
        self::assertTrue($this->store->hooks->hasFired('stock/moved'));
    }

    public function testReserveAndReleaseChangeOnlyReserved(): void
    {
        $this->store->inventory->reserve($this->variantId, $this->locationId, 5, $this->store->actor());

        $level = $this->store->inventory->level($this->variantId, $this->locationId);
        self::assertSame(20, (int) $level['on_hand']);
        self::assertSame(5, (int) $level['reserved']);
        self::assertSame(15, $this->store->inventory->availableAt($this->variantId, $this->locationId));

        $this->store->inventory->release($this->variantId, $this->locationId, 5, $this->store->actor());

        $level = $this->store->inventory->level($this->variantId, $this->locationId);
        self::assertSame(20, (int) $level['on_hand']);
        self::assertSame(0, (int) $level['reserved']);
    }

    public function testCommitReducesOnHandAndReserved(): void
    {
        $this->store->inventory->reserve($this->variantId, $this->locationId, 5, $this->store->actor());
        $this->store->inventory->commit($this->variantId, $this->locationId, 5, $this->store->actor());

        $level = $this->store->inventory->level($this->variantId, $this->locationId);
        self::assertSame(15, (int) $level['on_hand']);
        self::assertSame(0, (int) $level['reserved']);
        self::assertSame([], $this->store->inventory->verify());
    }

    public function testReservingMoreThanAvailableThrowsAndWritesNothing(): void
    {
        $before = $this->store->inventory->level($this->variantId, $this->locationId);
        $movementCount = count($this->store->inventory->movements($this->variantId, $this->locationId, 50));

        try {
            $this->store->inventory->reserve($this->variantId, $this->locationId, 21, $this->store->actor());
            self::fail('Expected InsufficientStock.');
        } catch (InsufficientStock $error) {
            self::assertSame(20, $error->available);
        }

        $after = $this->store->inventory->level($this->variantId, $this->locationId);
        self::assertSame((int) $before['reserved'], (int) $after['reserved']);
        self::assertSame($movementCount, count($this->store->inventory->movements($this->variantId, $this->locationId, 50)));
        self::assertSame([], $this->store->inventory->verify());
    }

    public function testCounterSaleMayOversellAndAlwaysRaisesACriticalAlert(): void
    {
        $this->store->inventory->counterSale($this->variantId, $this->locationId, 25, $this->store->actor());

        $level = $this->store->inventory->level($this->variantId, $this->locationId);
        self::assertSame(-5, (int) $level['on_hand']);

        $alerts = $this->store->alerts->open(AlertService::OVERSELL);
        self::assertCount(1, $alerts);
        self::assertSame('critical', $alerts[0]['severity']);
        self::assertTrue($this->store->hooks->hasFired('stock/oversold'));
    }

    public function testOversellAlertIsDeduplicated(): void
    {
        $this->store->inventory->counterSale($this->variantId, $this->locationId, 21, $this->store->actor());
        $this->store->inventory->counterSale($this->variantId, $this->locationId, 1, $this->store->actor());

        self::assertSame(1, $this->store->alerts->openCount(AlertService::OVERSELL));
    }

    public function testReceiveCreatesAReceiptAndIncreasesOnHand(): void
    {
        $receipt = $this->store->inventory->receive(
            $this->locationId,
            [['variant_id' => $this->variantId, 'qty' => 10]],
            $this->store->actor(),
            'Balogun supplier',
            'SUP-1'
        );

        self::assertSame($this->locationId, (int) $receipt['location_id']);
        self::assertSame(30, $this->store->inventory->level($this->variantId, $this->locationId)['on_hand'] + 10 - 10);
        self::assertSame(30, (int) $this->store->inventory->level($this->variantId, $this->locationId)['on_hand']);
        self::assertTrue($this->store->hooks->hasFired('stock/received'));
    }

    public function testAdjustmentRequiresAKnownReason(): void
    {
        $this->expectException(ValidationFailed::class);

        $this->store->inventory->adjust($this->variantId, $this->locationId, -3, 'because', $this->store->actor());
    }

    public function testAdjustmentWithReasonChangesOnHand(): void
    {
        $this->store->inventory->adjust($this->variantId, $this->locationId, -3, 'damaged', $this->store->actor(), 'Torn during packing');

        self::assertSame(17, (int) $this->store->inventory->level($this->variantId, $this->locationId)['on_hand']);
        self::assertSame([], $this->store->inventory->verify());
    }

    public function testTransfersMoveStockBetweenLocations(): void
    {
        $warehouse = $this->store->seedLocation('Warehouse', false);

        $transfer = $this->store->inventory->createTransfer(
            $this->locationId,
            (int) $warehouse['id'],
            [['variant_id' => $this->variantId, 'qty' => 5]],
            $this->store->actor()
        );

        $this->store->inventory->sendTransfer((int) $transfer['id'], $this->store->actor());
        self::assertSame(15, (int) $this->store->inventory->level($this->variantId, $this->locationId)['on_hand']);

        $this->store->inventory->receiveTransfer((int) $transfer['id'], $this->store->actor(), [$this->variantId => 4]);

        self::assertSame(4, (int) $this->store->inventory->level($this->variantId, (int) $warehouse['id'])['on_hand']);
        self::assertSame([], $this->store->inventory->verify());
    }

    public function testStockCountAppliesOneAdjustmentPerDifference(): void
    {
        $count = $this->store->inventory->startCount($this->locationId, $this->store->actor());
        $this->store->inventory->recordCount((int) $count['id'], $this->variantId, 18, $this->store->actor());
        $this->store->inventory->submitCount((int) $count['id'], $this->store->actor());

        $movements = $this->store->inventory->applyCount((int) $count['id'], $this->store->actor());

        self::assertCount(1, $movements);
        self::assertSame(-2, (int) $movements[0]['on_hand_delta']);
        self::assertSame('count', $movements[0]['reason']);
        self::assertSame(18, (int) $this->store->inventory->level($this->variantId, $this->locationId)['on_hand']);
        self::assertSame('applied', $this->store->inventoryRepository->count((int) $count['id'])['status']);
    }

    public function testVerifyDetectsTamperingAndFixesFromTheLedger(): void
    {
        $this->store->db->execute(
            sprintf('UPDATE %sstock_levels SET on_hand = on_hand + 7 WHERE variant_id = ?', $this->store->db->prefix()),
            [$this->variantId]
        );

        $mismatches = $this->store->inventory->verify();
        self::assertCount(1, $mismatches);
        self::assertSame('on_hand', $mismatches[0]['type']);
        self::assertSame(1, $this->store->alerts->openCount(AlertService::LEDGER_MISMATCH));

        $this->store->inventory->verify(null, null, true);

        self::assertSame([], $this->store->inventory->verify());
        self::assertSame(20, (int) $this->store->inventory->level($this->variantId, $this->locationId)['on_hand']);
    }

    public function testAvailableOnlineSumsOnlySellableLocations(): void
    {
        $online = $this->store->seedLocation('Online pool', true);
        $shopOnly = $this->store->seedLocation('Back room', false);

        $this->store->inventory->receive($this->locationId, [['variant_id' => $this->variantId, 'qty' => 5]], $this->store->actor());
        $this->store->inventory->receive((int) $online['id'], [['variant_id' => $this->variantId, 'qty' => 3]], $this->store->actor());
        $this->store->inventory->receive((int) $shopOnly['id'], [['variant_id' => $this->variantId, 'qty' => 100]], $this->store->actor());

        self::assertSame(28, $this->store->inventory->availableOnline($this->variantId));
    }
}
