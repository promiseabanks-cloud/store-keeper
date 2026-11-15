<?php

declare(strict_types=1);

/**
 * §21.2 concurrency probe: N parallel buyers try to reserve one unit of the same
 * variant at the same location. Prints a JSON summary on stdout.
 *
 * Usage: php concurrency-reserve.php <prefix> <variant_id> <location_id> [buyers]
 *
 * The worker holds no database connection before forking, so no child can
 * inherit (and later close) a connection owned by another process.
 */

require dirname(__DIR__, 2) . '/plugins/storekeeper-commerce/tests/bootstrap.php';

use StoreKeeper\Commerce\Alerts\AlertService;
use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Database\PdoConnection;
use StoreKeeper\Commerce\Core\FrozenClock;
use StoreKeeper\Commerce\Core\Hooks\InMemoryHooks;
use StoreKeeper\Commerce\Inventory\Exception\InsufficientStock;
use StoreKeeper\Commerce\Inventory\InventoryRepository;
use StoreKeeper\Commerce\Inventory\InventoryService;
use StoreKeeper\Commerce\Tests\Support\TestDatabase;

$prefix = (string) ($argv[1] ?? TestDatabase::DEFAULT_PREFIX);
$variantId = (int) ($argv[2] ?? 0);
$locationId = (int) ($argv[3] ?? 0);
$buyers = (int) ($argv[4] ?? 50);

$children = [];

for ($i = 0; $i < $buyers; $i++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        try {
            $db = new PdoConnection(TestDatabase::pdo(), $prefix, TestDatabase::DEFAULT_NETWORK_PREFIX);
            $clock = new FrozenClock();
            $service = new InventoryService(
                $db,
                new InventoryRepository($db, $clock),
                new InMemoryHooks(),
                $clock,
                new AlertService($db, $clock)
            );
            $service->reserve($variantId, $locationId, 1, Actor::system());
            exit(0);
        } catch (InsufficientStock) {
            exit(1);
        } catch (Throwable $error) {
            fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
            exit(2);
        }
    }

    $children[] = $pid;
}

$codes = [];
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
    $codes[] = pcntl_wexitstatus($status);
}

$db = new PdoConnection(TestDatabase::pdo(), $prefix, TestDatabase::DEFAULT_NETWORK_PREFIX);
$clock = new FrozenClock();
$repository = new InventoryRepository($db, $clock);
$service = new InventoryService($db, $repository, new InMemoryHooks(), $clock, new AlertService($db, $clock));

$level = $repository->level($variantId, $locationId);
$reserveMovements = (int) $db->scalar(
    sprintf("SELECT COUNT(*) FROM %sstock_movements WHERE variant_id = ? AND type = 'reserve'", $prefix),
    [$variantId]
);

echo json_encode([
    'buyers' => $buyers,
    'succeeded' => count(array_filter($codes, static fn (int $code): bool => $code === 0)),
    'insufficient' => count(array_filter($codes, static fn (int $code): bool => $code === 1)),
    'failed' => count(array_filter($codes, static fn (int $code): bool => $code === 2)),
    'reserved' => $level === null ? null : (int) $level['reserved'],
    'on_hand' => $level === null ? null : (int) $level['on_hand'],
    'reserve_movements' => $reserveMovements,
    'mismatches' => $service->verify(),
], JSON_THROW_ON_ERROR), "\n";
