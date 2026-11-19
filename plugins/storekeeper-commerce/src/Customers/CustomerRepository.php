<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Customers;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Customers, groups, addresses and special prices (§8.2).
 */
final class CustomerRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function groups(): array
    {
        return $this->db->select(sprintf('SELECT * FROM %scustomer_groups ORDER BY id ASC', $this->db->prefix()));
    }

    /** @return array<string,mixed>|null */
    public function group(int $groupId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %scustomer_groups WHERE id = ?', $this->db->prefix()),
            [$groupId]
        );
    }

    /**
     * Groups a shopper may choose at sign-up (§11.1).
     *
     * @return list<array<string,mixed>>
     */
    public function selfRegisterableGroups(): array
    {
        return $this->db->select(
            sprintf(
                'SELECT g.*, p.name AS price_list_name
                   FROM %scustomer_groups g
                   LEFT JOIN %sprice_lists p ON p.id = g.price_list_id
                  WHERE g.is_self_registerable = 1
                  ORDER BY g.id ASC',
                $this->db->prefix(),
                $this->db->prefix()
            )
        );
    }

    /** @return array<string,mixed>|null */
    public function defaultGroup(): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %scustomer_groups WHERE is_default = 1 ORDER BY id ASC LIMIT 1', $this->db->prefix())
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
    public function customerByUuid(string $uuid): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %scustomers WHERE uuid = ?', $this->db->prefix()),
            [$uuid]
        );
    }

    /** @return array<string,mixed>|null */
    public function customerByPhone(string $phoneE164): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %scustomers WHERE phone_e164 = ?', $this->db->prefix()),
            [$phoneE164]
        );
    }

    /** @return array<string,mixed>|null the account that signs in with this email */
    public function customerByEmail(string $email): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %scustomers WHERE email = ? ORDER BY id ASC LIMIT 1', $this->db->prefix()),
            [strtolower($email)]
        );
    }

    /** @return array<string,mixed>|null the account linked to this Google subject id */
    public function customerByGoogleSub(string $googleSub): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %scustomers WHERE google_sub = ?', $this->db->prefix()),
            [$googleSub]
        );
    }

    /** Store a new password hash (the service decides it is allowed). */
    public function setPassword(int $customerId, string $passwordHash): void
    {
        $this->update($customerId, ['password_hash' => $passwordHash]);
    }

    /** Link an existing account to a Google subject id. */
    public function setGoogleSub(int $customerId, string $googleSub): void
    {
        $this->update($customerId, ['google_sub' => $googleSub]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'customers', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $customerId, array $data): void
    {
        $data['updated_at'] = $this->clock->nowString();
        $this->db->update($this->db->prefix() . 'customers', $data, ['id' => $customerId]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    /**
     * The staff customer list with the group each customer is priced by.
     *
     * @param array<string,mixed> $filters search, approval_status, group_id
     * @return list<array<string,mixed>>
     */
    public function listWithGroup(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['approval_status'])) {
            $where[] = 'c.approval_status = ?';
            $params[] = $filters['approval_status'];
        }

        if (!empty($filters['group_id'])) {
            $where[] = 'c.group_id = ?';
            $params[] = (int) $filters['group_id'];
        }

        if (!empty($filters['search'])) {
            $like = '%' . $filters['search'] . '%';
            $where[] = '(c.name LIKE ? OR c.phone_e164 LIKE ? OR c.business_name LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        $params[] = $limit;
        $params[] = $offset;

        return $this->db->select(
            sprintf(
                'SELECT c.*, g.name AS group_name, g.price_list_id
                   FROM %scustomers c
                   LEFT JOIN %scustomer_groups g ON g.id = c.group_id
                  WHERE %s
                  ORDER BY c.id DESC LIMIT ? OFFSET ?',
                $this->db->prefix(),
                $this->db->prefix(),
                implode(' AND ', $where)
            ),
            $params
        );
    }

    public function search(string $query, int $limit = 20): array
    {
        $like = '%' . $query . '%';

        return $this->db->select(
            sprintf(
                'SELECT * FROM %scustomers WHERE name LIKE ? OR phone_e164 LIKE ? OR business_name LIKE ? ORDER BY name ASC LIMIT ?',
                $this->db->prefix()
            ),
            [$like, $like, $like, $limit]
        );
    }

    /** @param array<string,mixed> $data */
    public function createAddress(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'addresses', $data);
    }

    /** @return list<array<string,mixed>> */
    public function addresses(int $customerId): array
    {
        return $this->db->select(
            sprintf('SELECT * FROM %saddresses WHERE customer_id = ? ORDER BY is_default DESC, id ASC', $this->db->prefix()),
            [$customerId]
        );
    }

    /** @return array<string,mixed>|null */
    public function address(int $addressId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %saddresses WHERE id = ?', $this->db->prefix()),
            [$addressId]
        );
    }

    public function deleteAddress(int $addressId): void
    {
        $this->db->delete($this->db->prefix() . 'addresses', ['id' => $addressId]);
    }

    public function clearDefaultAddress(int $customerId): void
    {
        $this->db->execute(
            sprintf('UPDATE %saddresses SET is_default = 0 WHERE customer_id = ?', $this->db->prefix()),
            [$customerId]
        );
    }

    /** @param array<string,mixed> $data */
    public function upsertSpecialPrice(int $customerId, int $variantId, ?int $packUnitId, array $data): int
    {
        $table = $this->db->prefix() . 'customer_prices';
        $existing = $this->db->first(
            sprintf('SELECT id FROM %s WHERE customer_id = ? AND variant_id = ? AND pack_unit_id <=> ?', $table),
            [$customerId, $variantId, $packUnitId]
        );

        if ($existing !== null) {
            $this->db->update($table, $data, ['id' => $existing['id']]);

            return (int) $existing['id'];
        }

        return $this->db->insert($table, [
            'customer_id' => $customerId,
            'variant_id' => $variantId,
            'pack_unit_id' => $packUnitId,
            ...$data,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function specialPrices(int $customerId): array
    {
        return $this->db->select(
            sprintf('SELECT * FROM %scustomer_prices WHERE customer_id = ? ORDER BY variant_id ASC', $this->db->prefix()),
            [$customerId]
        );
    }

    /**
     * Recompute the denormalised spend columns from the customer's orders.
     *
     * Recomputed rather than incremented, so a cancelled order or a corrected
     * payment can never leave a customer's lifetime value permanently wrong.
     */
    public function refreshTotals(int $customerId): void
    {
        $this->db->execute(
            sprintf(
                'UPDATE %scustomers c
                   LEFT JOIN (
                        SELECT customer_id,
                               COUNT(*) AS order_count,
                               COALESCE(SUM(CASE WHEN status <> ? THEN paid_kobo ELSE 0 END), 0) AS spent_kobo,
                               MAX(created_at) AS last_order_at
                          FROM %sorders
                         WHERE customer_id = ?
                         GROUP BY customer_id
                   ) o ON o.customer_id = c.id
                    SET c.order_count = COALESCE(o.order_count, 0),
                        c.total_spent_kobo = COALESCE(o.spent_kobo, 0),
                        c.last_order_at = o.last_order_at,
                        c.updated_at = ?
                  WHERE c.id = ?',
                $this->db->prefix(),
                $this->db->prefix()
            ),
            ['cancelled', $customerId, $this->clock->nowString(), $customerId]
        );
    }

    public function incrementTotals(int $customerId, int $orderTotalKobo, string $at): void
    {
        $this->db->execute(
            sprintf(
                'UPDATE %scustomers
                    SET total_spent_kobo = total_spent_kobo + ?, order_count = order_count + 1,
                        last_order_at = ?, updated_at = ?
                  WHERE id = ?',
                $this->db->prefix()
            ),
            [$orderTotalKobo, $at, $this->clock->nowString(), $customerId]
        );
    }
}
