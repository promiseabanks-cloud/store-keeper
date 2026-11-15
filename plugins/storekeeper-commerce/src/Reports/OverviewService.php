<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Reports;

use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Read-only dashboard numbers (§11.7). Reports are the one place where the
 * engine queries aggregates directly; there are no writes here.
 */
final class OverviewService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $prefix = $this->db->prefix();

        $products = (int) $this->db->scalar(
            sprintf("SELECT COUNT(*) FROM %sproducts WHERE status <> 'archived'", $prefix)
        );
        $variants = (int) $this->db->scalar(
            sprintf("SELECT COUNT(*) FROM %svariants WHERE status = 'active'", $prefix)
        );

        $stockUnits = (int) $this->db->scalar(
            sprintf('SELECT COALESCE(SUM(on_hand - reserved), 0) FROM %sstock_levels', $prefix)
        );

        $lowStock = $this->db->select(
            sprintf(
                "SELECT v.id, v.sku, v.title, p.name AS product_name,
                        COALESCE(SUM(sl.on_hand - sl.reserved), 0) AS available,
                        COALESCE(v.low_stock_threshold, 5) AS threshold
                   FROM %svariants v
                   JOIN %sproducts p ON p.id = v.product_id
                   LEFT JOIN %sstock_levels sl ON sl.variant_id = v.id
                  WHERE v.status = 'active' AND p.status = 'active' AND p.track_stock = 1
                  GROUP BY v.id, v.sku, v.title, p.name, v.low_stock_threshold
                 HAVING available <= threshold
                  ORDER BY available ASC, v.id ASC
                  LIMIT 10",
                $prefix,
                $prefix,
                $prefix
            )
        );

        $openAlerts = (int) $this->db->scalar(
            sprintf("SELECT COUNT(*) FROM %salerts WHERE status = 'open'", $prefix)
        );

        $todaysOrders = (int) $this->db->scalar(
            sprintf('SELECT COUNT(*) FROM %sorders WHERE DATE(created_at) = UTC_DATE()', $prefix)
        );

        $awaitingPayment = (int) $this->db->scalar(
            sprintf(
                "SELECT COUNT(*) FROM %sorders WHERE status = 'open' AND payment_status IN ('unpaid','awaiting_confirmation')",
                $prefix
            )
        );

        return [
            'products' => $products,
            'variants' => $variants,
            'stock_units' => $stockUnits,
            'low_stock' => array_map(static fn (array $row): array => [
                'variant_id' => (int) $row['id'],
                'sku' => (string) $row['sku'],
                'title' => (string) $row['title'],
                'product_name' => (string) $row['product_name'],
                'available' => (int) $row['available'],
                'threshold' => (int) $row['threshold'],
            ], $lowStock),
            'open_alerts' => $openAlerts,
            'orders_today' => $todaysOrders,
            'awaiting_payment' => $awaitingPayment,
        ];
    }
}
