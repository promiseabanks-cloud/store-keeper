<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Inventory;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Data access for levels, movements, locations, receipts, transfers and counts.
 * Business rules live in InventoryService (§7.3).
 */
final class InventoryRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function location(int $locationId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %slocations WHERE id = ?', $this->db->prefix()),
            [$locationId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function locations(bool $onlySellable = false): array
    {
        $sql = sprintf('SELECT * FROM %slocations WHERE is_active = 1', $this->db->prefix());
        if ($onlySellable) {
            $sql .= ' AND sellable_online = 1';
        }
        $sql .= ' ORDER BY online_priority ASC, id ASC';

        return $this->db->select($sql);
    }

    /** @return array<string,mixed>|null */
    public function level(int $variantId, int $locationId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sstock_levels WHERE variant_id = ? AND location_id = ?', $this->db->prefix()),
            [$variantId, $locationId]
        );
    }

    /** Create the level row if it does not exist yet (§9.3). */
    public function ensureLevel(int $variantId, int $locationId): void
    {
        $this->db->execute(
            sprintf(
                'INSERT IGNORE INTO %sstock_levels (variant_id, location_id, on_hand, reserved, last_movement_id, updated_at)
                 VALUES (?, ?, 0, 0, NULL, ?)',
                $this->db->prefix()
            ),
            [$variantId, $locationId, $this->clock->nowString()]
        );
    }

    /** @return array<string,mixed>|null */
    public function lockLevel(int $variantId, int $locationId): ?array
    {
        return $this->db->first(
            sprintf(
                'SELECT * FROM %sstock_levels WHERE variant_id = ? AND location_id = ? FOR UPDATE',
                $this->db->prefix()
            ),
            [$variantId, $locationId]
        );
    }

    /**
     * Conditional reservation: succeeds only when enough is available (§9.4).
     * Returns affected rows (1 = reserved, 0 = not enough).
     */
    public function reserveIfAvailable(int $variantId, int $locationId, int $qty, int $movementId): int
    {
        return $this->db->execute(
            sprintf(
                'UPDATE %sstock_levels
                    SET reserved = reserved + ?, last_movement_id = ?, updated_at = ?
                  WHERE variant_id = ? AND location_id = ? AND on_hand - reserved >= ?',
                $this->db->prefix()
            ),
            [$qty, $movementId, $this->clock->nowString(), $variantId, $locationId, $qty]
        );
    }

    /** Conditional release: never lets reserved go below zero. */
    public function releaseIfReserved(int $variantId, int $locationId, int $qty, int $movementId): int
    {
        return $this->db->execute(
            sprintf(
                'UPDATE %sstock_levels
                    SET reserved = reserved - ?, last_movement_id = ?, updated_at = ?
                  WHERE variant_id = ? AND location_id = ? AND reserved >= ?',
                $this->db->prefix()
            ),
            [$qty, $movementId, $this->clock->nowString(), $variantId, $locationId, $qty]
        );
    }

    /**
     * Apply a movement's deltas to the cached level (§9.3).
     */
    public function applyMovement(
        int $variantId,
        int $locationId,
        int $onHandDelta,
        int $reservedDelta,
        int $movementId,
    ): int {
        return $this->db->execute(
            sprintf(
                'UPDATE %sstock_levels
                    SET on_hand = on_hand + ?, reserved = reserved + ?, last_movement_id = ?, updated_at = ?
                  WHERE variant_id = ? AND location_id = ?',
                $this->db->prefix()
            ),
            [
                $onHandDelta,
                $reservedDelta,
                $movementId,
                $this->clock->nowString(),
                $variantId,
                $locationId,
            ]
        );
    }

    /** @param array<string,mixed> $movement */
    public function insertMovement(array $movement): int
    {
        return $this->db->insert($this->db->prefix() . 'stock_movements', $movement);
    }

    public function availableAt(int $variantId, int $locationId): int
    {
        return (int) $this->db->scalar(
            sprintf(
                'SELECT COALESCE(on_hand - reserved, 0) FROM %sstock_levels WHERE variant_id = ? AND location_id = ?',
                $this->db->prefix()
            ),
            [$variantId, $locationId]
        );
    }

    /**
     * Sum of available across locations the storefront sells from (§9.1).
     *
     * When a buffer and cutoff are given, a location whose active device has not
     * synced since the cutoff contributes `available − buffer` (never below zero)
     * instead of its full level (§15.2, D-041).
     */
    public function availableOnline(int $variantId, int $bufferUnits = 0, ?string $staleCutoff = null): int
    {
        $prefix = $this->db->prefix();
        $params = [];
        $available = $this->availabilityExpression($bufferUnits, $staleCutoff, $params);
        $params[] = $variantId;

        return (int) $this->db->scalar(
            sprintf(
                'SELECT COALESCE(SUM(%s), 0)
                   FROM %sstock_levels l
                   JOIN %slocations loc ON loc.id = l.location_id
                  WHERE l.variant_id = ? AND loc.sellable_online = 1 AND loc.is_active = 1',
                $available,
                $prefix,
                $prefix
            ),
            $params
        );
    }

    /**
     * Online availability for many variants at once, so a product grid costs one
     * query instead of one per variant (§9.1).
     *
     * @param list<int> $variantIds
     * @return array<int,int> variant id => available pieces
     */
    public function availableOnlineMany(array $variantIds, int $bufferUnits = 0, ?string $staleCutoff = null): array
    {
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));
        if ($variantIds === []) {
            return [];
        }

        $prefix = $this->db->prefix();
        $params = [];
        $available = $this->availabilityExpression($bufferUnits, $staleCutoff, $params);
        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $rows = $this->db->select(
            sprintf(
                'SELECT l.variant_id, COALESCE(SUM(%s), 0) AS available
                   FROM %sstock_levels l
                   JOIN %slocations loc ON loc.id = l.location_id
                  WHERE l.variant_id IN (' . $placeholders . ')
                    AND loc.sellable_online = 1 AND loc.is_active = 1
                  GROUP BY l.variant_id',
                $available,
                $prefix,
                $prefix
            ),
            [...$params, ...$variantIds]
        );

        $available = array_fill_keys($variantIds, 0);
        foreach ($rows as $row) {
            $available[(int) $row['variant_id']] = (int) $row['available'];
        }

        return $available;
    }

    /**
     * Per-location availability, with the offline safety buffer applied only to
     * locations whose active device is stale (§15.2, D-041).
     *
     * @param list<int|string|null> $params receives the cutoff value, so it must
     *        be read before any other placeholder is appended
     */
    private function availabilityExpression(int $bufferUnits, ?string $staleCutoff, array &$params): string
    {
        if ($bufferUnits <= 0 || $staleCutoff === null) {
            return 'l.on_hand - l.reserved';
        }

        $params[] = $staleCutoff;

        return sprintf(
            "CASE WHEN EXISTS (
                        SELECT 1 FROM %sdevices d
                         WHERE d.location_id = loc.id
                           AND d.status = 'active'
                           AND (d.last_sync_at IS NULL OR d.last_sync_at < ?)
                    )
                    THEN GREATEST(l.on_hand - l.reserved - %d, 0)
                    ELSE l.on_hand - l.reserved
              END",
            $this->db->prefix(),
            $bufferUnits
        );
    }

    public function onHandAt(int $variantId, int $locationId): int
    {
        return (int) $this->db->scalar(
            sprintf('SELECT COALESCE(on_hand, 0) FROM %sstock_levels WHERE variant_id = ? AND location_id = ?', $this->db->prefix()),
            [$variantId, $locationId]
        );
    }

    public function reservedAt(int $variantId, int $locationId): int
    {
        return (int) $this->db->scalar(
            sprintf('SELECT COALESCE(reserved, 0) FROM %sstock_levels WHERE variant_id = ? AND location_id = ?', $this->db->prefix()),
            [$variantId, $locationId]
        );
    }

    /** Reserved units still held for one order line, straight from the ledger (§9.6). */
    public function reservedForOrderItem(int $orderItemId): int
    {
        return (int) $this->db->scalar(
            sprintf('SELECT COALESCE(SUM(reserved_delta), 0) FROM %sstock_movements WHERE order_item_id = ?', $this->db->prefix()),
            [$orderItemId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function reservedOrderItemIds(int $orderId): array
    {
        return $this->db->select(
            sprintf(
                'SELECT DISTINCT order_item_id FROM %sstock_movements WHERE order_id = ? AND order_item_id IS NOT NULL',
                $this->db->prefix()
            ),
            [$orderId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function movements(int $variantId, ?int $locationId = null, int $limit = 100): array
    {
        $sql = sprintf('SELECT * FROM %sstock_movements WHERE variant_id = ?', $this->db->prefix());
        $params = [$variantId];

        if ($locationId !== null) {
            $sql .= ' AND location_id = ?';
            $params[] = $locationId;
        }

        $sql .= ' ORDER BY id DESC LIMIT ?';
        $params[] = $limit;

        return $this->db->select($sql, $params);
    }

    /**
     * Ledger sums per variant/location, used by the verifier (§9.6).
     *
     * @return list<array<string,mixed>>
     */
    public function ledgerSums(?int $variantId = null, ?int $locationId = null): array
    {
        $sql = sprintf(
            'SELECT variant_id, location_id, SUM(on_hand_delta) AS on_hand, SUM(reserved_delta) AS reserved, MAX(id) AS last_movement_id
               FROM %sstock_movements WHERE 1 = 1',
            $this->db->prefix()
        );
        $params = [];

        if ($variantId !== null) {
            $sql .= ' AND variant_id = ?';
            $params[] = $variantId;
        }

        if ($locationId !== null) {
            $sql .= ' AND location_id = ?';
            $params[] = $locationId;
        }

        $sql .= ' GROUP BY variant_id, location_id';

        return $this->db->select($sql, $params);
    }

    /** @return list<array<string,mixed>> */
    public function levels(?int $variantId = null, ?int $locationId = null): array
    {
        $sql = sprintf('SELECT * FROM %sstock_levels WHERE 1 = 1', $this->db->prefix());
        $params = [];

        if ($variantId !== null) {
            $sql .= ' AND variant_id = ?';
            $params[] = $variantId;
        }

        if ($locationId !== null) {
            $sql .= ' AND location_id = ?';
            $params[] = $locationId;
        }

        return $this->db->select($sql, $params);
    }

    public function rewriteLevel(
        int $variantId,
        int $locationId,
        int $onHand,
        int $reserved,
        ?int $lastMovementId,
    ): void {
        $this->db->execute(
            sprintf(
                'UPDATE %sstock_levels SET on_hand = ?, reserved = ?, last_movement_id = ?, updated_at = ?
                  WHERE variant_id = ? AND location_id = ?',
                $this->db->prefix()
            ),
            [$onHand, $reserved, $lastMovementId, $this->clock->nowString(), $variantId, $locationId]
        );
    }

    /** @return array<string,mixed>|null */
    public function variant(int $variantId): ?array
    {
        return $this->db->first(
            sprintf(
                'SELECT v.*, p.name AS product_name, p.track_stock, p.allow_backorder, p.status AS product_status
                   FROM %svariants v JOIN %sproducts p ON p.id = v.product_id
                  WHERE v.id = ?',
                $this->db->prefix(),
                $this->db->prefix()
            ),
            [$variantId]
        );
    }

    public function createReceipt(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'stock_receipts', $data);
    }

    public function createTransfer(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'stock_transfers', $data);
    }

    public function createTransferLine(int $transferId, int $variantId, int $qty): void
    {
        $this->db->insert($this->db->prefix() . 'stock_transfer_lines', [
            'transfer_id' => $transferId,
            'variant_id' => $variantId,
            'qty' => $qty,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function transfer(int $transferId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sstock_transfers WHERE id = ?', $this->db->prefix()),
            [$transferId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function transferLines(int $transferId): array
    {
        return $this->db->select(
            sprintf('SELECT * FROM %sstock_transfer_lines WHERE transfer_id = ?', $this->db->prefix()),
            [$transferId]
        );
    }

    public function updateTransfer(int $transferId, array $data): void
    {
        $this->db->update($this->db->prefix() . 'stock_transfers', $data, ['id' => $transferId]);
    }

    public function updateTransferLine(int $transferId, int $variantId, int $qtyReceived): void
    {
        $this->db->update(
            $this->db->prefix() . 'stock_transfer_lines',
            ['qty_received' => $qtyReceived],
            ['transfer_id' => $transferId, 'variant_id' => $variantId]
        );
    }

    public function createCount(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'stock_counts', $data);
    }

    /** @return array<string,mixed>|null */
    public function count(int $countId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sstock_counts WHERE id = ?', $this->db->prefix()),
            [$countId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function countLines(int $countId): array
    {
        return $this->db->select(
            sprintf('SELECT * FROM %sstock_count_lines WHERE count_id = ?', $this->db->prefix()),
            [$countId]
        );
    }

    public function upsertCountLine(
        int $countId,
        int $variantId,
        int $expectedQty,
        int $countedQty,
        int $countedBy,
    ): void {
        $this->db->execute(
            sprintf(
                'INSERT INTO %sstock_count_lines (count_id, variant_id, expected_qty, counted_qty, counted_by, counted_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE counted_qty = VALUES(counted_qty), counted_by = VALUES(counted_by), counted_at = VALUES(counted_at)',
                $this->db->prefix()
            ),
            [$countId, $variantId, $expectedQty, $countedQty, $countedBy, $this->clock->nowString()]
        );
    }

    public function updateCount(int $countId, array $data): void
    {
        $this->db->update($this->db->prefix() . 'stock_counts', $data, ['id' => $countId]);
    }

    public function createLocation(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'locations', $data);
    }
}
