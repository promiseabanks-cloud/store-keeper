<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * §21.2: 50 parallel reservations for a variant with 10 available must produce
 * exactly 10 successes and 40 insufficient_stock failures, with a clean ledger.
 *
 * The load runs in tools/scripts/concurrency-reserve.php so the forked workers
 * never inherit this process's MySQL connection.
 */
final class ReservationConcurrencyTest extends TestCase
{
    public function testOnlyTheAvailableUnitsAreReservedUnderConcurrency(): void
    {
        $store = TestStore::create();
        $store->seedGroups();
        $locationId = (int) $store->seedLocation()['id'];
        $variantId = (int) $store->seedProduct($locationId, 500000, 10)['variant']['id'];

        $command = sprintf(
            '%s %s %s %d %d 50',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(SK_ROOT . '/tools/scripts/concurrency-reserve.php'),
            escapeshellarg($store->db->prefix()),
            $variantId,
            $locationId
        );

        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);

        $json = implode("\n", $output);
        $result = json_decode($json, true);
        self::assertIsArray($result, 'Concurrency worker produced no JSON: ' . $json);
        self::assertSame(0, $status, 'Worker failed: ' . $json);

        self::assertSame(50, $result['buyers']);
        self::assertSame(0, $result['failed'], 'No reservation may fail for a non-stock reason');
        self::assertSame(10, $result['succeeded'], 'Exactly the available units must be reserved');
        self::assertSame(40, $result['insufficient'], 'Every other buyer must be rejected cleanly');
        self::assertSame(10, $result['reserved']);
        self::assertSame(0, $result['on_hand'] - $result['reserved']);
        self::assertSame(10, $result['reserve_movements']);
        self::assertSame([], $result['mismatches']);
    }
}
