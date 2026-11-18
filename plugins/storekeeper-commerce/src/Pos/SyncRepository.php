<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Pos;

use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Read-only queries for the device snapshot `/sync/pull` sends (§15.2, §15.3).
 * Nothing here writes: sync never changes prices or stock by itself.
 */
final class SyncRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Active products and variants with their single-unit base price.
     *
     * @return list<array<string,mixed>>
     */
    public function catalog(): array
    {
        $prefix = $this->db->prefix();

        return $this->db->select(sprintf(
            "SELECT v.id AS variant_id, v.uuid AS variant_uuid, v.sku, v.barcode, v.title,
                    v.weight_grams, p.id AS product_id, p.uuid AS product_uuid,
                    p.name AS product_name, p.base_unit_label,
                    (SELECT MIN(pr.amount_kobo) FROM %sprices pr
                      WHERE pr.variant_id = v.id AND pr.min_qty = 1
                        AND pr.price_list_id = (SELECT id FROM %sprice_lists WHERE is_base = 1 ORDER BY id ASC LIMIT 1)) AS price_kobo
               FROM %svariants v
               JOIN %sproducts p ON p.id = v.product_id
              WHERE v.status = 'active' AND p.status = 'active'
              ORDER BY p.name ASC, v.sort_order ASC, v.id ASC",
            $prefix,
            $prefix,
            $prefix,
            $prefix
        ));
    }

    /** @return list<array<string,mixed>> */
    public function packUnits(): array
    {
        return $this->db->select(
            sprintf('SELECT id, product_id, name, pieces FROM %spack_units ORDER BY product_id ASC, sort_order ASC, id ASC', $this->db->prefix())
        );
    }

    /**
     * Every list price, so the device can show the right price per group while
     * offline. Special customer prices are sent separately by customer.
     *
     * @return list<array<string,mixed>>
     */
    public function prices(): array
    {
        $prefix = $this->db->prefix();

        return $this->db->select(sprintf(
            'SELECT pr.price_list_id, pr.variant_id, pr.pack_unit_id, pr.min_qty, pr.amount_kobo, pl.name AS price_list_name
               FROM %sprices pr
               JOIN %sprice_lists pl ON pl.id = pr.price_list_id
              ORDER BY pr.variant_id ASC, pr.price_list_id ASC, pr.min_qty ASC',
            $prefix,
            $prefix
        ));
    }

    /** @return list<array<string,mixed>> */
    public function groups(): array
    {
        return $this->db->select(
            sprintf('SELECT id, name, type, price_list_id, approval FROM %scustomer_groups ORDER BY id ASC', $this->db->prefix())
        );
    }

    /** @return list<array<string,mixed>> */
    public function levels(int $locationId): array
    {
        return $this->db->select(
            sprintf(
                'SELECT variant_id, on_hand, reserved FROM %sstock_levels WHERE location_id = ? ORDER BY variant_id ASC',
                $this->db->prefix()
            ),
            [$locationId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function customerPrices(int $limit = 500): array
    {
        return $this->db->select(
            sprintf(
                'SELECT customer_id, variant_id, pack_unit_id, amount_kobo, valid_from, valid_to
                   FROM %scustomer_prices ORDER BY customer_id ASC LIMIT ?',
                $this->db->prefix()
            ),
            [$limit]
        );
    }

    /**
     * Customers with recent orders, which is the list the brief allows the
     * device to carry offline (§15.2).
     *
     * @return list<array<string,mixed>>
     */
    public function customers(int $limit = 500): array
    {
        return $this->db->select(
            sprintf(
                "SELECT id, uuid, name, business_name, phone_e164, group_id
                   FROM %scustomers
                  WHERE approval_status = 'approved'
                  ORDER BY COALESCE(last_order_at, created_at) DESC, id DESC
                  LIMIT ?",
                $this->db->prefix()
            ),
            [$limit]
        );
    }

    /** @return array<string,mixed>|null */
    public function orderByClientUuid(string $clientUuid): ?array
    {
        $prefix = $this->db->prefix();

        return $this->db->first(
            sprintf(
                'SELECT o.* FROM %spos_sales ps JOIN %sorders o ON o.id = ps.order_id WHERE ps.client_uuid = ?',
                $prefix,
                $prefix
            ),
            [$clientUuid]
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

    /**
     * Draft sales parked on this device (§15.1 "hold/park a sale").
     *
     * @return list<array<string,mixed>>
     */
    public function heldByDevice(int $deviceId, int $limit = 50): array
    {
        $prefix = $this->db->prefix();

        return $this->db->select(sprintf(
            "SELECT o.*, ps.cashier_user_id
               FROM %sorders o
               JOIN %spos_sales ps ON ps.order_id = o.id
              WHERE o.status = 'draft' AND ps.device_id = ?
              ORDER BY o.created_at DESC, o.id DESC LIMIT ?",
            $prefix,
            $prefix
        ), [$deviceId, $limit]);
    }

    /** @return list<array<string,mixed>> */
    public function itemsForOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($orderIds), '?'));

        return $this->db->select(
            sprintf(
                'SELECT * FROM %sorder_items WHERE order_id IN (%s) ORDER BY order_id ASC, id ASC',
                $this->db->prefix(),
                $placeholders
            ),
            array_map('intval', $orderIds)
        );
    }

    /**
     * Cash taken by this device/cashier on a business date, net of cash refunds.
     *
     * @return array{cash_in_kobo:int,cash_refunded_kobo:int}
     */
    public function cashForDay(int $deviceId, int $cashierUserId, string $date): array
    {
        $prefix = $this->db->prefix();

        // A sale made offline belongs to the day it was rung up, not the day
        // the batch reached the server (§15.3 rule 6).
        $cashIn = (int) $this->db->scalar(sprintf(
            "SELECT COALESCE(SUM(p.amount_kobo), 0)
               FROM %spayments p
               JOIN %spos_sales ps ON ps.order_id = p.order_id
               JOIN %sorders o ON o.id = p.order_id
              WHERE p.status = 'succeeded' AND p.method = 'cash'
                AND ps.device_id = ? AND ps.cashier_user_id = ?
                AND DATE(COALESCE(o.client_created_at, ps.created_at)) = ?",
            $prefix,
            $prefix,
            $prefix
        ), [$deviceId, $cashierUserId, $date]);

        $refunded = (int) $this->db->scalar(sprintf(
            "SELECT COALESCE(SUM(r.amount_kobo), 0)
               FROM %srefunds r
               JOIN %spos_sales ps ON ps.order_id = r.order_id
              WHERE r.method = 'cash'
                AND ps.device_id = ? AND ps.cashier_user_id = ?
                AND DATE(r.created_at) = ?",
            $prefix,
            $prefix
        ), [$deviceId, $cashierUserId, $date]);

        return ['cash_in_kobo' => $cashIn, 'cash_refunded_kobo' => $refunded];
    }

    /** @return array<string,mixed>|null */
    public function cashUpFor(int $deviceId, int $cashierUserId, string $date): ?array
    {
        return $this->db->first(
            sprintf(
                'SELECT * FROM %scash_ups WHERE device_id = ? AND cashier_user_id = ? AND business_date = ?',
                $this->db->prefix()
            ),
            [$deviceId, $cashierUserId, $date]
        );
    }

    /** @param array<string,mixed> $data */
    public function createCashUp(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'cash_ups', $data);
    }

    /** @param array<string,mixed> $data */
    public function updateCashUp(int $id, array $data): void
    {
        $this->db->update($this->db->prefix() . 'cash_ups', $data, ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function cashUpById(int $id): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %scash_ups WHERE id = ?', $this->db->prefix()),
            [$id]
        );
    }
}
