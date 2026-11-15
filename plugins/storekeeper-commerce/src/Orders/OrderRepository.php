<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Orders;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Orders, order lines, payments and the sequence counter (§8.4, §12.5).
 */
final class OrderRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Store prefix + sequence, e.g. "DJ-10423". Gaps are allowed, repeats are
     * impossible because the increment happens in one statement (§12.5).
     */
    public function nextNumber(string $prefix, string $sequence = 'order'): string
    {
        $table = $this->db->prefix() . 'sequences';

        $updated = $this->db->execute(
            sprintf('UPDATE %s SET value = LAST_INSERT_ID(value + 1) WHERE name = ?', $table),
            [$sequence]
        );

        if ($updated === 0) {
            $this->db->execute(
                sprintf('INSERT IGNORE INTO %s (name, value) VALUES (?, 1)', $table),
                [$sequence]
            );
            // A concurrent request may have inserted the row first.
            if ($this->db->execute(sprintf('UPDATE %s SET value = LAST_INSERT_ID(value + 1) WHERE name = ?', $table), [$sequence]) === 0) {
                $this->db->execute(sprintf('UPDATE %s SET value = LAST_INSERT_ID(value) WHERE name = ?', $table), [$sequence]);
            }
        }

        $value = (int) $this->db->scalar('SELECT LAST_INSERT_ID()');

        return sprintf('%s-%05d', strtoupper($prefix), $value);
    }

    /** @param array<string,mixed> $data */
    public function createOrder(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'orders', $data);
    }

    /** @param array<string,mixed> $data */
    public function createItem(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'order_items', $data);
    }

    /** @return array<string,mixed>|null */
    public function order(int $orderId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sorders WHERE id = ?', $this->db->prefix()),
            [$orderId]
        );
    }

    /** @return array<string,mixed>|null */
    public function orderByUuid(string $uuid): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sorders WHERE uuid = ?', $this->db->prefix()),
            [$uuid]
        );
    }

    /** @return array<string,mixed>|null */
    public function orderByIdempotencyKey(string $key): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sorders WHERE idempotency_key = ?', $this->db->prefix()),
            [$key]
        );
    }

    /** @return array<string,mixed>|null */
    public function orderByNumber(string $number): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sorders WHERE number = ?', $this->db->prefix()),
            [$number]
        );
    }

    /** @return array<string,mixed>|null */
    public function orderByPublicToken(string $number, string $token): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sorders WHERE number = ? AND public_token = ?', $this->db->prefix()),
            [$number, $token]
        );
    }

    /** @param array<string,mixed> $data */
    public function updateOrder(int $orderId, array $data): void
    {
        $data['updated_at'] = $this->clock->nowString();
        $this->db->update($this->db->prefix() . 'orders', $data, ['id' => $orderId]);
    }

    /** @return list<array<string,mixed>> */
    public function items(int $orderId): array
    {
        return $this->db->select(
            sprintf('SELECT * FROM %sorder_items WHERE order_id = ? ORDER BY id ASC', $this->db->prefix()),
            [$orderId]
        );
    }

    /** @param array<string,mixed> $data */
    public function updateItem(int $orderItemId, array $data): void
    {
        $this->db->update($this->db->prefix() . 'order_items', $data, ['id' => $orderItemId]);
    }

    /** @return list<array<string,mixed>> */
    public function payments(int $orderId): array
    {
        return $this->db->select(
            sprintf('SELECT * FROM %spayments WHERE order_id = ? ORDER BY id ASC', $this->db->prefix()),
            [$orderId]
        );
    }

    /** @return array<string,mixed>|null */
    public function customer(int $customerId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %scustomers WHERE id = ?', $this->db->prefix()),
            [$customerId]
        );
    }

    /** @return array<string,mixed>|null */
    public function customerGroup(int $groupId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %scustomer_groups WHERE id = ?', $this->db->prefix()),
            [$groupId]
        );
    }

    /** @return array<string,mixed>|null */
    public function defaultGroup(): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %scustomer_groups WHERE is_default = 1 ORDER BY id ASC LIMIT 1', $this->db->prefix())
        );
    }

    /**
     * Orders with the customer name attached, for the back office list.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function listDetailed(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['1 = 1'];
        $params = [];

        foreach (['status', 'payment_status', 'fulfilment_status', 'channel'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = 'o.' . $field . ' = ?';
                $params[] = $filters[$field];
            }
        }

        if (!empty($filters['customer_id'])) {
            $where[] = 'o.customer_id = ?';
            $params[] = (int) $filters['customer_id'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(o.number LIKE ? OR c.name LIKE ? OR c.phone_e164 LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $params[] = $limit;
        $params[] = $offset;

        $rows = $this->db->select(
            sprintf(
                'SELECT o.*, c.name AS customer_name, c.phone_e164 AS customer_phone
                   FROM %sorders o
                   LEFT JOIN %scustomers c ON c.id = o.customer_id
                  WHERE %s
                  ORDER BY o.id DESC LIMIT ? OFFSET ?',
                $this->db->prefix(),
                $this->db->prefix(),
                implode(' AND ', $where)
            ),
            $params
        );

        return $rows;
    }

    /**
     * Orders list with filters (§11.8), newest first.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['1 = 1'];
        $params = [];

        foreach (['status', 'payment_status', 'fulfilment_status', 'channel', 'customer_group_id', 'location_id'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = $field . ' = ?';
                $params[] = $filters[$field];
            }
        }

        if (!empty($filters['search'])) {
            $where[] = '(number LIKE ? OR customer_id IN (SELECT id FROM ' . $this->db->prefix() . 'customers WHERE name LIKE ? OR phone_e164 LIKE ?))';
            $like = '%' . $filters['search'] . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $params[] = $limit;
        $params[] = $offset;

        return $this->db->select(
            sprintf(
                'SELECT * FROM %sorders WHERE %s ORDER BY id DESC LIMIT ? OFFSET ?',
                $this->db->prefix(),
                implode(' AND ', $where)
            ),
            $params
        );
    }
}
