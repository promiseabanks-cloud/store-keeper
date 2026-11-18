<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Inventory;

use StoreKeeper\Commerce\Alerts\AlertService;
use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Exception\NotFound;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Hooks\Hooks;
use StoreKeeper\Commerce\Core\Uuid;
use StoreKeeper\Commerce\Inventory\Exception\InsufficientStock;
use StoreKeeper\Commerce\Inventory\Exception\InvalidStockOperation;

/**
 * The stock ledger (§9). Every method that changes stock:
 *   1. runs inside one database transaction,
 *   2. appends exactly one stock_movements row,
 *   3. updates stock_levels.last_movement_id in the same transaction,
 *   4. fires hooks only after commit.
 *
 * on_hand may only go negative through an offline counter sale (§9.9); every
 * other path is guarded so the ledger cannot be corrupted from the engine.
 */
final class InventoryService
{
    public function __construct(
        private readonly Connection $db,
        private readonly InventoryRepository $repo,
        private readonly Hooks $hooks,
        private readonly Clock $clock,
        private readonly AlertService $alerts,
        private readonly int $defaultLowStockThreshold = 5,
        private readonly int $offlineBufferUnits = 0,
        private readonly int $deviceStaleMinutes = 10,
    ) {
    }

    /**
     * First stock entry for a variant (§9.2 `opening`).
     *
     * @return array<string,mixed> the movement row
     */
    public function opening(int $variantId, int $locationId, int $qty, Actor $actor, ?string $note = null): array
    {
        $this->assertQty($qty, false);
        $movement = $this->db->transaction(function () use ($variantId, $locationId, $qty, $actor, $note): array {
            $this->lockOrCreateLevel($variantId, $locationId);
            $movement = $this->insertMovement($variantId, $locationId, MovementType::OPENING, $qty, 0, $actor, ['note' => $note]);
            $this->repo->applyMovement($variantId, $locationId, $qty, 0, $movement['id']);

            return $movement;
        });

        $this->afterMovement($movement);

        return $movement;
    }

    /**
     * Stock received from a supplier (§11.3 restock screen).
     *
     * @param list<array{variant_id:int,qty:int,cost_kobo?:int|null}> $lines
     * @return array<string,mixed> the receipt
     */
    public function receive(
        int $locationId,
        array $lines,
        Actor $actor,
        ?string $supplier = null,
        ?string $reference = null,
    ): array {
        if ($lines === []) {
            throw new ValidationFailed('A receipt needs at least one line.');
        }

        $receiptId = $this->db->transaction(function () use ($locationId, $lines, $actor, $supplier, $reference): int {
            $receiptId = $this->repo->createReceipt([
                'uuid' => Uuid::v4(),
                'location_id' => $locationId,
                'supplier' => $supplier,
                'reference' => $reference,
                'total_cost_kobo' => null,
                'created_by' => $actor->id ?? 0,
                'created_at' => $this->clock->nowString(),
            ]);

            // Fixed lock order by variant id prevents deadlocks between two receipts (§9.4).
            usort($lines, static fn (array $a, array $b): int => (int) $a['variant_id'] <=> (int) $b['variant_id']);

            foreach ($lines as $line) {
                $this->assertQty((int) $line['qty'], false);
                $this->lockOrCreateLevel((int) $line['variant_id'], $locationId);
                $movement = $this->insertMovement(
                    (int) $line['variant_id'],
                    $locationId,
                    MovementType::RECEIVE,
                    (int) $line['qty'],
                    0,
                    $actor,
                    ['receipt_id' => $receiptId]
                );
                $this->repo->applyMovement((int) $line['variant_id'], $locationId, (int) $line['qty'], 0, $movement['id']);
            }

            return $receiptId;
        });

        $receipt = $this->db->first(
            sprintf('SELECT * FROM %sstock_receipts WHERE id = ?', $this->db->prefix()),
            [$receiptId]
        ) ?? [];

        $this->hooks->do('stock/received', $receipt);

        foreach ($lines as $line) {
            $this->checkLowStock((int) $line['variant_id']);
        }

        return $receipt;
    }

    /**
     * Reserve stock for an order line (§9.4). Uses a single conditional UPDATE,
     * so two buyers can never both get the last unit.
     *
     * @return array<string,mixed> the movement row
     */
    public function reserve(
        int $variantId,
        int $locationId,
        int $qty,
        Actor $actor,
        ?int $orderId = null,
        ?int $orderItemId = null,
    ): array {
        $this->assertQty($qty);

        $movement = $this->db->transaction(function () use ($variantId, $locationId, $qty, $actor, $orderId, $orderItemId): array {
            $this->lockOrCreateLevel($variantId, $locationId);
            $movement = $this->insertMovement($variantId, $locationId, MovementType::RESERVE, 0, $qty, $actor, [
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
            ]);

            if ($this->repo->reserveIfAvailable($variantId, $locationId, $qty, $movement['id']) === 0) {
                throw new InsufficientStock(
                    $variantId,
                    $locationId,
                    $qty,
                    $this->repo->availableAt($variantId, $locationId),
                    $orderId
                );
            }

            return $movement;
        });

        $this->afterMovement($movement);

        return $movement;
    }

    /**
     * Release a reservation: cancellation, reduced line, or an expired hold (§9.2).
     *
     * @return array<string,mixed> the movement row
     */
    public function release(
        int $variantId,
        int $locationId,
        int $qty,
        Actor $actor,
        ?int $orderId = null,
        ?int $orderItemId = null,
        ?string $reason = null,
    ): array {
        $this->assertQty($qty);

        $movement = $this->db->transaction(function () use ($variantId, $locationId, $qty, $actor, $orderId, $orderItemId, $reason): array {
            $movement = $this->insertMovement($variantId, $locationId, MovementType::RELEASE, 0, -$qty, $actor, [
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'reason' => $reason,
            ]);

            if ($this->repo->releaseIfReserved($variantId, $locationId, $qty, $movement['id']) === 0) {
                throw new InvalidStockOperation(sprintf(
                    'Cannot release %d of variant %d at location %d: only %d reserved.',
                    $qty,
                    $variantId,
                    $locationId,
                    $this->repo->reservedAt($variantId, $locationId)
                ));
            }

            return $movement;
        });

        $this->afterMovement($movement);

        return $movement;
    }

    /**
     * Commit reserved goods: on_hand and reserved both go down (§9.2, D-012).
     *
     * @return array<string,mixed> the movement row
     */
    public function commit(
        int $variantId,
        int $locationId,
        int $qty,
        Actor $actor,
        ?int $orderId = null,
        ?int $orderItemId = null,
    ): array {
        $this->assertQty($qty);

        $movement = $this->db->transaction(function () use ($variantId, $locationId, $qty, $actor, $orderId, $orderItemId): array {
            $level = $this->repo->lockLevel($variantId, $locationId);
            if ($level === null || (int) $level['reserved'] < $qty) {
                throw new InvalidStockOperation(sprintf(
                    'Cannot commit %d of variant %d at location %d: only %d reserved.',
                    $qty,
                    $variantId,
                    $locationId,
                    $level === null ? 0 : (int) $level['reserved']
                ));
            }

            $movement = $this->insertMovement($variantId, $locationId, MovementType::COMMIT, -$qty, -$qty, $actor, [
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
            ]);
            $this->repo->applyMovement($variantId, $locationId, -$qty, -$qty, $movement['id']);

            return $movement;
        });

        $this->afterMovement($movement);

        return $movement;
    }

    /**
     * POS counter sale: goods leave immediately and were never reserved (§9.2, §9.9).
     * This is the only path allowed to push on_hand below zero, and it always
     * raises an oversell alert when it does.
     *
     * @return array<string,mixed> the movement row
     */
    public function counterSale(
        int $variantId,
        int $locationId,
        int $qty,
        Actor $actor,
        ?int $orderId = null,
        ?int $orderItemId = null,
        ?int $deviceId = null,
        ?string $clientCreatedAt = null,
    ): array {
        $this->assertQty($qty);

        $movement = $this->db->transaction(function () use ($variantId, $locationId, $qty, $actor, $orderId, $orderItemId, $deviceId, $clientCreatedAt): array {
            $this->lockOrCreateLevel($variantId, $locationId);
            $movement = $this->insertMovement($variantId, $locationId, MovementType::COUNTER_SALE, -$qty, 0, $actor, [
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'device_id' => $deviceId,
                'client_created_at' => $clientCreatedAt,
            ]);
            $this->repo->applyMovement($variantId, $locationId, -$qty, 0, $movement['id']);

            return $movement;
        });

        $level = $this->repo->level($variantId, $locationId);
        if ($level !== null && ((int) $level['on_hand'] < 0 || (int) $level['on_hand'] < (int) $level['reserved'])) {
            $shortfall = max(0, (int) $level['reserved'] - (int) $level['on_hand'], -(int) $level['on_hand']);
            $this->alerts->raise(AlertService::OVERSELL, AlertService::CRITICAL, 'variant', $variantId, [
                'location_id' => $locationId,
                'on_hand' => (int) $level['on_hand'],
                'reserved' => (int) $level['reserved'],
                'shortfall' => $shortfall,
                'movement_id' => $movement['id'],
            ]);
            $this->hooks->do('stock/oversold', [
                'variant_id' => $variantId,
                'location_id' => $locationId,
                'shortfall' => $shortfall,
                'movement' => $movement,
            ]);
        }

        $this->afterMovement($movement);

        return $movement;
    }

    /**
     * Customer returned goods that go back on the shelf (§9.2 `return`).
     *
     * @return array<string,mixed> the movement row
     */
    public function returnStock(
        int $variantId,
        int $locationId,
        int $qty,
        Actor $actor,
        ?int $orderId = null,
        ?string $note = null,
    ): array {
        $this->assertQty($qty);

        $movement = $this->db->transaction(function () use ($variantId, $locationId, $qty, $actor, $orderId, $note): array {
            $this->lockOrCreateLevel($variantId, $locationId);
            $movement = $this->insertMovement($variantId, $locationId, MovementType::RETURN, $qty, 0, $actor, [
                'order_id' => $orderId,
                'note' => $note,
            ]);
            $this->repo->applyMovement($variantId, $locationId, $qty, 0, $movement['id']);

            return $movement;
        });

        $this->afterMovement($movement);

        return $movement;
    }

    /**
     * Manual adjustment with a required reason (§9.2, §9.7).
     *
     * @return array<string,mixed> the movement row
     */
    public function adjust(
        int $variantId,
        int $locationId,
        int $delta,
        string $reason,
        Actor $actor,
        ?string $note = null,
        ?int $countId = null,
    ): array {
        if ($delta === 0) {
            throw new ValidationFailed('An adjustment of zero changes nothing.', ['delta' => 'Must not be zero.']);
        }

        if (!in_array($reason, ['count', 'damaged', 'lost', 'found', 'correction', 'import'], true)) {
            throw new ValidationFailed('Unknown adjustment reason.', ['reason' => 'count, damaged, lost, found, correction or import.']);
        }

        $movement = $this->db->transaction(function () use ($variantId, $locationId, $delta, $reason, $actor, $note, $countId): array {
            $this->lockOrCreateLevel($variantId, $locationId);
            $movement = $this->insertMovement($variantId, $locationId, MovementType::ADJUST, $delta, 0, $actor, [
                'reason' => $reason,
                'note' => $note,
                'count_id' => $countId,
            ]);
            $this->repo->applyMovement($variantId, $locationId, $delta, 0, $movement['id']);

            return $movement;
        });

        $this->afterMovement($movement);

        return $movement;
    }

    /**
     * A shop, store or warehouse stock can live in (§9.1).
     *
     * @param array<string,mixed> $input name, type, address, flags
     * @return array<string,mixed>
     */
    public function createLocation(array $input, Actor $actor): array
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            throw new ValidationFailed('A location name is required.', ['name' => 'Required.']);
        }

        $type = (string) ($input['type'] ?? 'shop');
        if (!in_array($type, ['shop', 'warehouse', 'store', 'other'], true)) {
            throw new ValidationFailed('Unknown location type.', ['type' => 'shop, warehouse, store or other.']);
        }

        $locationId = $this->repo->createLocation([
            'uuid' => Uuid::v4(),
            'name' => $name,
            'type' => $type,
            'sellable_online' => !empty($input['sellable_online']) ? 1 : 0,
            'online_priority' => max(1, (int) ($input['online_priority'] ?? 1)),
            'address' => trim((string) ($input['address'] ?? '')) ?: null,
            'is_pickup_point' => !empty($input['is_pickup_point']) ? 1 : 0,
            'is_active' => 1,
            'created_at' => $this->clock->nowString(),
        ]);

        $location = $this->repo->location($locationId) ?? [];

        $this->hooks->do('location/created', ['location' => $location, 'actor' => $actor->type]);

        return $location;
    }

    /**
     * Release whatever is still reserved for an order's lines (§12.1 cancel).
     */
    public function releaseReservationsForOrder(int $orderId, Actor $actor, ?string $reason = 'order_cancelled'): int
    {
        $released = 0;

        foreach ($this->repo->reservedOrderItemIds($orderId) as $row) {
            $orderItemId = (int) $row['order_item_id'];
            $reserved = $this->repo->reservedForOrderItem($orderItemId);
            if ($reserved <= 0) {
                continue;
            }

            $locationId = (int) $this->db->scalar(
                sprintf('SELECT location_id FROM %sorder_items WHERE id = ?', $this->db->prefix()),
                [$orderItemId]
            );
            $variantId = (int) $this->db->scalar(
                sprintf('SELECT variant_id FROM %sorder_items WHERE id = ?', $this->db->prefix()),
                [$orderItemId]
            );

            $this->release($variantId, $locationId, $reserved, $actor, $orderId, $orderItemId, $reason);
            $released += $reserved;
        }

        return $released;
    }

    /**
     * Commit every remaining reservation of an order (handover/packing, §14.1).
     */
    public function commitReservationsForOrder(int $orderId, Actor $actor): int
    {
        $committed = 0;

        foreach ($this->repo->reservedOrderItemIds($orderId) as $row) {
            $orderItemId = (int) $row['order_item_id'];
            $reserved = $this->repo->reservedForOrderItem($orderItemId);
            if ($reserved <= 0) {
                continue;
            }

            $item = $this->db->first(
                sprintf('SELECT variant_id, location_id FROM %sorder_items WHERE id = ?', $this->db->prefix()),
                [$orderItemId]
            );
            if ($item === null) {
                continue;
            }

            $this->commit((int) $item['variant_id'], (int) $item['location_id'], $reserved, $actor, $orderId, $orderItemId);
            $committed += $reserved;
        }

        return $committed;
    }

    /**
     * Create a stock transfer in draft (§11.3 transfer between locations).
     *
     * @param list<array{variant_id:int,qty:int}> $lines
     * @return array<string,mixed>
     */
    public function createTransfer(int $fromLocationId, int $toLocationId, array $lines, Actor $actor): array
    {
        if ($fromLocationId === $toLocationId) {
            throw new ValidationFailed('A transfer must go to a different location.');
        }

        if ($lines === []) {
            throw new ValidationFailed('A transfer needs at least one line.');
        }

        $transferId = $this->db->transaction(function () use ($fromLocationId, $toLocationId, $lines, $actor): int {
            $transferId = $this->repo->createTransfer([
                'uuid' => Uuid::v4(),
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'status' => 'draft',
                'created_by' => $actor->id ?? 0,
                'created_at' => $this->clock->nowString(),
            ]);

            foreach ($lines as $line) {
                $this->assertQty((int) $line['qty'], false);
                $this->repo->createTransferLine($transferId, (int) $line['variant_id'], (int) $line['qty']);
            }

            return $transferId;
        });

        return $this->repo->transfer($transferId) ?? [];
    }

    /**
     * Send a transfer: stock leaves the source location (§9.2 transfer_out).
     */
    public function sendTransfer(int $transferId, Actor $actor): array
    {
        $this->db->transaction(function () use ($transferId, $actor): void {
            $transfer = $this->repo->transfer($transferId);
            if ($transfer === null) {
                throw new NotFound('transfer', $transferId);
            }

            if ($transfer['status'] !== 'draft') {
                throw new InvalidStockOperation('Only a draft transfer can be sent.');
            }

            $fromLocationId = (int) $transfer['from_location_id'];

            foreach ($this->repo->transferLines($transferId) as $line) {
                $variantId = (int) $line['variant_id'];
                $qty = (int) $line['qty'];
                $onHand = $this->repo->onHandAt($variantId, $fromLocationId);

                if ($onHand < $qty) {
                    throw new InsufficientStock($variantId, $fromLocationId, $qty, $onHand);
                }

                $movement = $this->insertMovement($variantId, $fromLocationId, MovementType::TRANSFER_OUT, -$qty, 0, $actor, [
                    'transfer_id' => $transferId,
                ]);
                $this->repo->applyMovement($variantId, $fromLocationId, -$qty, 0, $movement['id']);
            }

            $this->repo->updateTransfer($transferId, [
                'status' => 'sent',
                'sent_at' => $this->clock->nowString(),
            ]);
        });

        $transfer = $this->repo->transfer($transferId) ?? [];
        $this->hooks->do('stock/transfer_sent', $transfer);

        return $transfer;
    }

    /**
     * Receive a transfer: stock arrives at the destination (§9.2 transfer_in).
     *
     * @param array<int,int>|null $quantitiesReceived variant_id => qty_received
     */
    public function receiveTransfer(int $transferId, Actor $actor, ?array $quantitiesReceived = null): array
    {
        $this->db->transaction(function () use ($transferId, $actor, $quantitiesReceived): void {
            $transfer = $this->repo->transfer($transferId);
            if ($transfer === null) {
                throw new NotFound('transfer', $transferId);
            }

            if ($transfer['status'] !== 'sent') {
                throw new InvalidStockOperation('Only a sent transfer can be received.');
            }

            $toLocationId = (int) $transfer['to_location_id'];

            foreach ($this->repo->transferLines($transferId) as $line) {
                $variantId = (int) $line['variant_id'];
                $received = $quantitiesReceived[$variantId] ?? (int) $line['qty'];
                $this->assertQty($received, false);

                $this->lockOrCreateLevel($variantId, $toLocationId);
                if ($received > 0) {
                    $movement = $this->insertMovement($variantId, $toLocationId, MovementType::TRANSFER_IN, $received, 0, $actor, [
                        'transfer_id' => $transferId,
                    ]);
                    $this->repo->applyMovement($variantId, $toLocationId, $received, 0, $movement['id']);
                }

                $this->repo->updateTransferLine($transferId, $variantId, $received);
            }

            $this->repo->updateTransfer($transferId, [
                'status' => 'received',
                'received_at' => $this->clock->nowString(),
            ]);
        });

        $transfer = $this->repo->transfer($transferId) ?? [];
        $this->hooks->do('stock/transfer_received', $transfer);

        return $transfer;
    }

    /**
     * Start a stock count (§9.7).
     *
     * @return array<string,mixed>
     */
    public function startCount(int $locationId, Actor $actor, ?string $scope = null): array
    {
        $countId = $this->repo->createCount([
            'uuid' => Uuid::v4(),
            'location_id' => $locationId,
            'scope' => $scope,
            'status' => 'open',
            'created_by' => $actor->id ?? 0,
            'created_at' => $this->clock->nowString(),
        ]);

        return $this->repo->count($countId) ?? [];
    }

    /**
     * Record a counted quantity; the expected quantity is snapshotted now (§9.7).
     */
    public function recordCount(int $countId, int $variantId, int $countedQty, Actor $actor): void
    {
        $this->assertQty($countedQty, false);

        $count = $this->repo->count($countId);
        if ($count === null) {
            throw new NotFound('stock count', $countId);
        }

        if ($count['status'] !== 'open' && $count['status'] !== 'submitted') {
            throw new InvalidStockOperation('This count is already closed.');
        }

        $expected = $this->repo->onHandAt($variantId, (int) $count['location_id']);
        $this->repo->upsertCountLine($countId, $variantId, $expected, $countedQty, $actor->id ?? 0);
    }

    public function submitCount(int $countId, Actor $actor): array
    {
        $count = $this->repo->count($countId);
        if ($count === null) {
            throw new NotFound('stock count', $countId);
        }

        $this->repo->updateCount($countId, ['status' => 'submitted']);

        return $this->repo->count($countId) ?? [];
    }

    /**
     * Apply a submitted count: one `adjust` movement per difference (§9.7).
     *
     * @return list<array<string,mixed>> the movements created
     */
    public function applyCount(int $countId, Actor $actor): array
    {
        $count = $this->repo->count($countId);
        if ($count === null) {
            throw new NotFound('stock count', $countId);
        }

        if (!in_array($count['status'], ['open', 'submitted'], true)) {
            throw new InvalidStockOperation('Only an open or submitted count can be applied.');
        }

        $locationId = (int) $count['location_id'];
        $movements = [];

        foreach ($this->repo->countLines($countId) as $line) {
            $delta = (int) $line['counted_qty'] - (int) $line['expected_qty'];
            if ($delta === 0) {
                continue;
            }

            $movements[] = $this->adjust(
                (int) $line['variant_id'],
                $locationId,
                $delta,
                'count',
                $actor,
                sprintf('Stock count %d', $countId),
                $countId
            );
        }

        $this->repo->updateCount($countId, [
            'status' => 'applied',
            'applied_at' => $this->clock->nowString(),
        ]);

        return $movements;
    }

    /**
     * Ledger integrity check (§9.6). Recomputes on_hand/reserved from movements
     * and compares with the cached level row.
     *
     * @return list<array{variant_id:int,location_id:int,type:string,expected:int,actual:int}>
     */
    public function verify(?int $variantId = null, ?int $locationId = null, bool $fix = false): array
    {
        $ledger = [];
        foreach ($this->repo->ledgerSums($variantId, $locationId) as $sum) {
            $ledger[(int) $sum['variant_id'] . ':' . (int) $sum['location_id']] = [
                'on_hand' => (int) $sum['on_hand'],
                'reserved' => (int) $sum['reserved'],
                'last_movement_id' => $sum['last_movement_id'] === null ? null : (int) $sum['last_movement_id'],
            ];
        }

        $mismatches = [];

        foreach ($this->repo->levels($variantId, $locationId) as $level) {
            $key = (int) $level['variant_id'] . ':' . (int) $level['location_id'];
            $expectedOnHand = $ledger[$key]['on_hand'] ?? 0;
            $expectedReserved = $ledger[$key]['reserved'] ?? 0;
            unset($ledger[$key]);

            if ((int) $level['on_hand'] !== $expectedOnHand) {
                $mismatches[] = [
                    'variant_id' => (int) $level['variant_id'],
                    'location_id' => (int) $level['location_id'],
                    'type' => 'on_hand',
                    'expected' => $expectedOnHand,
                    'actual' => (int) $level['on_hand'],
                ];
            }

            if ((int) $level['reserved'] !== $expectedReserved) {
                $mismatches[] = [
                    'variant_id' => (int) $level['variant_id'],
                    'location_id' => (int) $level['location_id'],
                    'type' => 'reserved',
                    'expected' => $expectedReserved,
                    'actual' => (int) $level['reserved'],
                ];
            }

            if ($fix && ((int) $level['on_hand'] !== $expectedOnHand || (int) $level['reserved'] !== $expectedReserved)) {
                // The ledger is the source of truth; only the cache is rewritten (§9.6).
                $this->repo->rewriteLevel(
                    (int) $level['variant_id'],
                    (int) $level['location_id'],
                    $expectedOnHand,
                    $expectedReserved,
                    $ledger[$key]['last_movement_id'] ?? $this->lastMovementId($level)
                );
            }
        }

        if ($mismatches !== []) {
            $this->alerts->raise(AlertService::LEDGER_MISMATCH, AlertService::CRITICAL, 'store', null, [
                'mismatches' => $mismatches,
                'fixed' => $fix,
            ], false);
        }

        return $mismatches;
    }

    /** Available units at a location (on_hand − reserved). */
    public function availableAt(int $variantId, int $locationId): int
    {
        return $this->repo->availableAt($variantId, $locationId);
    }

    /** Storefront availability: sum across sellable locations (§9.1, §15.2). */
    public function availableOnline(int $variantId): int
    {
        [$buffer, $cutoff] = $this->availabilityPolicy();

        return $this->repo->availableOnline($variantId, $buffer, $cutoff);
    }

    /**
     * Storefront availability for many variants in one query, with the same
     * offline safety buffer as `availableOnline` (§15.2, D-041).
     *
     * @param list<int> $variantIds
     * @return array<int,int>
     */
    public function availableOnlineMany(array $variantIds): array
    {
        [$buffer, $cutoff] = $this->availabilityPolicy();

        return $this->repo->availableOnlineMany($variantIds, $buffer, $cutoff);
    }

    /**
     * The location a line should reserve from: first sellable location (by
     * online_priority) with enough available stock (D-005, §9.4).
     */
    public function findReservationLocation(int $variantId, int $qty): ?int
    {
        foreach ($this->repo->locations(true) as $location) {
            if ($this->repo->availableAt($variantId, (int) $location['id']) >= $qty) {
                return (int) $location['id'];
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    public function movements(int $variantId, ?int $locationId = null, int $limit = 100): array
    {
        return $this->repo->movements($variantId, $locationId, $limit);
    }

    /** @return list<array<string,mixed>> */
    public function locations(bool $onlySellable = false): array
    {
        return $this->repo->locations($onlySellable);
    }

    /** @return array<string,mixed>|null */
    public function level(int $variantId, int $locationId): ?array
    {
        return $this->repo->level($variantId, $locationId);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function insertMovement(
        int $variantId,
        int $locationId,
        string $type,
        int $onHandDelta,
        int $reservedDelta,
        Actor $actor,
        array $extra = [],
    ): array {
        $data = [
            'uuid' => Uuid::v4(),
            'variant_id' => $variantId,
            'location_id' => $locationId,
            'type' => $type,
            'on_hand_delta' => $onHandDelta,
            'reserved_delta' => $reservedDelta,
            'reason' => $extra['reason'] ?? null,
            'order_id' => $extra['order_id'] ?? null,
            'order_item_id' => $extra['order_item_id'] ?? null,
            'transfer_id' => $extra['transfer_id'] ?? null,
            'count_id' => $extra['count_id'] ?? null,
            'receipt_id' => $extra['receipt_id'] ?? null,
            'actor_type' => $this->actorType($actor),
            'actor_id' => $actor->id,
            'device_id' => $extra['device_id'] ?? null,
            'client_created_at' => $extra['client_created_at'] ?? null,
            'note' => $extra['note'] ?? null,
            'created_at' => $this->clock->nowString(),
        ];

        $data['id'] = $this->repo->insertMovement($data);

        return $data;
    }

    /** @param array<string,mixed> $movement */
    private function afterMovement(array $movement): void
    {
        $this->hooks->do('stock/moved', $movement);
        $this->checkLowStock((int) $movement['variant_id']);
    }

    private function checkLowStock(int $variantId): void
    {
        $variant = $this->repo->variant($variantId);
        if ($variant === null || (int) $variant['track_stock'] !== 1) {
            return;
        }

        $threshold = $variant['low_stock_threshold'] === null
            ? $this->defaultLowStockThreshold
            : (int) $variant['low_stock_threshold'];
        [$buffer, $cutoff] = $this->availabilityPolicy();
        $available = $this->repo->availableOnline($variantId, $buffer, $cutoff);

        if ($available <= $threshold) {
            $this->alerts->raise(AlertService::LOW_STOCK, AlertService::WARNING, 'variant', $variantId, [
                'available' => $available,
                'threshold' => $threshold,
                'sku' => $variant['sku'],
                'product_name' => $variant['product_name'],
            ]);
            $this->hooks->do('stock/low', [
                'variant_id' => $variantId,
                'available' => $available,
                'threshold' => $threshold,
            ]);
        }
    }

    /**
     * The storefront buffer and the cutoff that makes a device stale.
     *
     * @return array{0:int,1:?string} buffer units, cutoff (null when buffering is off)
     */
    private function availabilityPolicy(): array
    {
        if ($this->offlineBufferUnits <= 0) {
            return [0, null];
        }

        $cutoff = $this->clock->now()
            ->modify(sprintf('-%d minutes', max(1, $this->deviceStaleMinutes)))
            ->format('Y-m-d H:i:s.v');

        return [$this->offlineBufferUnits, $cutoff];
    }

    /** @param array<string,mixed> $level */
    private function lastMovementId(array $level): ?int
    {
        return $level['last_movement_id'] === null ? null : (int) $level['last_movement_id'];
    }

    /**
     * Take the row's exclusive lock before touching it, so two concurrent
     * reservations cannot deadlock by upgrading a shared lock (§9.3, D-006).
     */
    private function lockOrCreateLevel(int $variantId, int $locationId): void
    {
        if ($this->repo->lockLevel($variantId, $locationId) === null) {
            $this->repo->ensureLevel($variantId, $locationId);
            $this->repo->lockLevel($variantId, $locationId);
        }
    }

    private function actorType(Actor $actor): string
    {
        return match ($actor->type) {
            Actor::STAFF, Actor::OWNER_IMPERSONATION => 'staff',
            Actor::CUSTOMER => 'customer',
            Actor::DEVICE => 'device',
            default => 'system',
        };
    }

    private function assertQty(int $qty, bool $strictlyPositive = true): void
    {
        if ($qty < 0 || ($strictlyPositive && $qty === 0)) {
            throw new ValidationFailed('Quantity must be a positive whole number.', ['qty' => 'Must be 1 or more.']);
        }
    }
}
