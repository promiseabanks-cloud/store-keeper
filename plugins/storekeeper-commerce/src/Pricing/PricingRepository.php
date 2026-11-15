<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Pricing;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Reads/writes for price lists, tiers, customer groups and special prices (§8.2).
 * No business rules here: resolution lives in PricingService (§7.3).
 */
final class PricingRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function group(int $groupId): ?array
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

    /** @return array<string,mixed>|null */
    public function priceList(int $priceListId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sprice_lists WHERE id = ?', $this->db->prefix()),
            [$priceListId]
        );
    }

    /** @return array<string,mixed>|null */
    public function basePriceList(): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sprice_lists WHERE is_base = 1 ORDER BY id ASC LIMIT 1', $this->db->prefix())
        );
    }

    /**
     * Best tier whose minimum quantity is reached (§10.1 step 2).
     *
     * @return array<string,mixed>|null
     */
    public function bestTier(int $priceListId, int $variantId, ?int $packUnitId, int $qty): ?array
    {
        return $this->db->first(
            sprintf(
                'SELECT * FROM %sprices
                  WHERE price_list_id = ? AND variant_id = ? AND pack_unit_id <=> ? AND min_qty <= ?
                  ORDER BY min_qty DESC LIMIT 1',
                $this->db->prefix()
            ),
            [$priceListId, $variantId, $packUnitId, $qty]
        );
    }

    /**
     * The lowest tier regardless of quantity, used when a group ignores tier
     * minimums so an approved wholesaler never falls back to retail (R1, §10.1).
     *
     * @return array<string,mixed>|null
     */
    public function lowestTier(int $priceListId, int $variantId, ?int $packUnitId): ?array
    {
        return $this->db->first(
            sprintf(
                'SELECT * FROM %sprices
                  WHERE price_list_id = ? AND variant_id = ? AND pack_unit_id <=> ?
                  ORDER BY min_qty ASC LIMIT 1',
                $this->db->prefix()
            ),
            [$priceListId, $variantId, $packUnitId]
        );
    }

    /**
     * A special price agreed with one customer, valid at $at (§8.2).
     *
     * @return array<string,mixed>|null
     */
    public function customerPrice(int $customerId, int $variantId, ?int $packUnitId, string $at): ?array
    {
        return $this->db->first(
            sprintf(
                'SELECT * FROM %scustomer_prices
                  WHERE customer_id = ? AND variant_id = ? AND pack_unit_id <=> ?
                    AND (valid_from IS NULL OR valid_from <= ?)
                    AND (valid_to IS NULL OR valid_to >= ?)
                  ORDER BY id DESC LIMIT 1',
                $this->db->prefix()
            ),
            [$customerId, $variantId, $packUnitId, $at, $at]
        );
    }

    /** @return array<string,mixed>|null */
    public function packUnit(int $packUnitId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %spack_units WHERE id = ?', $this->db->prefix()),
            [$packUnitId]
        );
    }

    /** Create or update a price tier (§11.3 bulk edit, §10.5). */
    public function upsertPrice(int $priceListId, int $variantId, ?int $packUnitId, int $minQty, int $amountKobo): int
    {
        $table = $this->db->prefix() . 'prices';
        $existing = $this->db->first(
            sprintf(
                'SELECT id FROM %s WHERE price_list_id = ? AND variant_id = ? AND pack_unit_id <=> ? AND min_qty = ?',
                $table
            ),
            [$priceListId, $variantId, $packUnitId, $minQty]
        );

        if ($existing !== null) {
            $this->db->update($table, ['amount_kobo' => $amountKobo, 'updated_at' => $this->clock->nowString()], ['id' => $existing['id']]);

            return (int) $existing['id'];
        }

        return $this->db->insert($table, [
            'price_list_id' => $priceListId,
            'variant_id' => $variantId,
            'pack_unit_id' => $packUnitId,
            'min_qty' => $minQty,
            'amount_kobo' => $amountKobo,
            'updated_at' => $this->clock->nowString(),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function priceById(int $priceId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sprices WHERE id = ?', $this->db->prefix()),
            [$priceId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function createList(string $name, string $currency, bool $isBase): int
    {
        return $this->db->insert($this->db->prefix() . 'price_lists', [
            'name' => $name,
            'currency' => $currency,
            'is_base' => $isBase ? 1 : 0,
            'created_at' => $this->clock->nowString(),
        ]);
    }
}
