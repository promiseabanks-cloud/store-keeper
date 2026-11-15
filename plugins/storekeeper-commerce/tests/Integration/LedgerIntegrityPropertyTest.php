<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Inventory\Exception\InsufficientStock;
use StoreKeeper\Commerce\Inventory\Exception\InvalidStockOperation;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * §21.1: after any random sequence of operations, stock_levels must equal the
 * sums over stock_movements and reserved must never be negative.
 */
final class LedgerIntegrityPropertyTest extends TestCase
{
    public function testRandomWorkloadKeepsTheLedgerConsistent(): void
    {
        $store = TestStore::create();
        $store->seedGroups();
        $locationId = (int) $store->seedLocation()['id'];
        $variantId = (int) $store->seedProduct($locationId, 500000, 40)['variant']['id'];

        mt_srand(20261115);

        $operations = 250;
        for ($i = 0; $i < $operations; $i++) {
            $level = $store->inventory->level($variantId, $locationId);
            $reserved = (int) $level['reserved'];
            $available = (int) $level['on_hand'] - $reserved;

            $operation = mt_rand(1, 100);

            try {
                if ($operation <= 25) {
                    $store->inventory->receive($locationId, [['variant_id' => $variantId, 'qty' => mt_rand(1, 8)]], $store->actor());
                } elseif ($operation <= 55) {
                    $store->inventory->reserve($variantId, $locationId, mt_rand(1, 6), $store->actor());
                } elseif ($operation <= 70) {
                    $qty = min(mt_rand(1, 4), $reserved);
                    if ($qty > 0) {
                        $store->inventory->release($variantId, $locationId, $qty, $store->actor());
                    }
                } elseif ($operation <= 85) {
                    $qty = min(mt_rand(1, 4), $reserved);
                    if ($qty > 0) {
                        $store->inventory->commit($variantId, $locationId, $qty, $store->actor());
                    }
                } elseif ($operation <= 95) {
                    $store->inventory->counterSale($variantId, $locationId, mt_rand(1, 3), $store->actor());
                } else {
                    $delta = mt_rand(-5, 5);
                    if ($delta !== 0) {
                        $store->inventory->adjust($variantId, $locationId, $delta, $delta > 0 ? 'found' : 'damaged', $store->actor());
                    }
                }
            } catch (InsufficientStock | InvalidStockOperation) {
                // Rejected operations must leave no trace; the assertions below prove it.
            }

            self::assertSame([], $store->inventory->verify(), 'Levels diverged from the ledger at operation ' . $i);

            $level = $store->inventory->level($variantId, $locationId);
            self::assertGreaterThanOrEqual(0, (int) $level['reserved'], 'Reserved went negative at operation ' . $i);
        }

        $onHand = (int) $store->db->scalar(
            sprintf('SELECT SUM(on_hand_delta) FROM %sstock_movements WHERE variant_id = ?', $store->db->prefix()),
            [$variantId]
        );
        $reserved = (int) $store->db->scalar(
            sprintf('SELECT SUM(reserved_delta) FROM %sstock_movements WHERE variant_id = ?', $store->db->prefix()),
            [$variantId]
        );

        $level = $store->inventory->level($variantId, $locationId);
        self::assertSame($onHand, (int) $level['on_hand']);
        self::assertSame($reserved, (int) $level['reserved']);
        self::assertGreaterThanOrEqual(0, $reserved, 'Reserved stock can never be negative');
    }
}
