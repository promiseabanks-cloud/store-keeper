<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Cart;

use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Cart rows only. Every price, name and stock figure is resolved by
 * CartService, so nothing here can go stale in the database (§20).
 */
final class CartRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'carts', $data);
    }

    /** @return array<string,mixed>|null */
    public function byTokenHash(string $tokenHash): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %scarts WHERE token_hash = ? LIMIT 1', $this->db->prefix()),
            [$tokenHash]
        );
    }

    /** @return array<string,mixed>|null */
    public function byId(int $cartId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %scarts WHERE id = ? LIMIT 1', $this->db->prefix()),
            [$cartId]
        );
    }

    /** @param array<string,mixed> $data */
    public function update(int $cartId, array $data): void
    {
        $this->db->update($this->db->prefix() . 'carts', $data, ['id' => $cartId]);
    }

    /** @return list<array<string,mixed>> */
    public function items(int $cartId): array
    {
        return $this->db->select(
            sprintf('SELECT * FROM %scart_items WHERE cart_id = ? ORDER BY id ASC', $this->db->prefix()),
            [$cartId]
        );
    }

    /** @return array<string,mixed>|null */
    public function line(int $cartId, int $variantId, int $packUnitId): ?array
    {
        return $this->db->first(
            sprintf(
                'SELECT * FROM %scart_items WHERE cart_id = ? AND variant_id = ? AND pack_unit_id = ? LIMIT 1',
                $this->db->prefix()
            ),
            [$cartId, $variantId, $packUnitId]
        );
    }

    /** @return array<string,mixed>|null */
    public function lineById(int $cartId, int $lineId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %scart_items WHERE cart_id = ? AND id = ? LIMIT 1', $this->db->prefix()),
            [$cartId, $lineId]
        );
    }

    /** @param array<string,mixed> $data */
    public function insertLine(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'cart_items', $data);
    }

    public function updateQty(int $lineId, int $qty): void
    {
        $this->db->update($this->db->prefix() . 'cart_items', ['qty' => $qty], ['id' => $lineId]);
    }

    public function deleteLine(int $lineId): void
    {
        $this->db->delete($this->db->prefix() . 'cart_items', ['id' => $lineId]);
    }

    public function clear(int $cartId): void
    {
        $this->db->delete($this->db->prefix() . 'cart_items', ['cart_id' => $cartId]);
    }

    public function lineCount(int $cartId): int
    {
        return (int) $this->db->scalar(
            sprintf('SELECT COALESCE(SUM(qty), 0) FROM %scart_items WHERE cart_id = ?', $this->db->prefix()),
            [$cartId]
        );
    }
}
