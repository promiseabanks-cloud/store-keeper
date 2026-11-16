<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Catalog;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Catalog data access (§8.1). Business rules live in CatalogService (§7.3).
 */
final class CatalogRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    public function prefix(): string
    {
        return $this->db->prefix();
    }

    /** @param array<string,mixed> $data */
    public function createProduct(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'products', $data);
    }

    /** @return array<string,mixed>|null */
    public function product(int $productId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sproducts WHERE id = ?', $this->db->prefix()),
            [$productId]
        );
    }

    /** @return array<string,mixed>|null */
    public function productByUuid(string $uuid): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sproducts WHERE uuid = ?', $this->db->prefix()),
            [$uuid]
        );
    }

    /** @return array<string,mixed>|null */
    public function productBySlug(string $slug): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sproducts WHERE slug = ?', $this->db->prefix()),
            [$slug]
        );
    }

    /** @return array<string,mixed>|null */
    public function categoryBySlug(string $slug): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %scategories WHERE slug = ?', $this->db->prefix()),
            [$slug]
        );
    }

    /** @return list<array<string,mixed>> */
    public function categories(): array
    {
        return $this->db->select(
            sprintf('SELECT * FROM %scategories ORDER BY sort_order ASC, name ASC', $this->db->prefix())
        );
    }

    public function productSlugExists(string $slug): bool
    {
        return (bool) $this->db->scalar(
            sprintf('SELECT 1 FROM %sproducts WHERE slug = ? LIMIT 1', $this->db->prefix()),
            [$slug]
        );
    }

    public function productNameExists(string $name): bool
    {
        return (bool) $this->db->scalar(
            sprintf('SELECT 1 FROM %sproducts WHERE name = ? LIMIT 1', $this->db->prefix()),
            [$name]
        );
    }

    /** @param array<string,mixed> $data */
    public function updateProduct(int $productId, array $data): void
    {
        $data['updated_at'] = $this->clock->nowString();
        $this->db->update($this->db->prefix() . 'products', $data, ['id' => $productId]);
    }

    /** @return array<string,mixed>|null */
    public function optionBySlug(string $slug): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %soptions WHERE slug = ?', $this->db->prefix()),
            [$slug]
        );
    }

    /** @param array<string,mixed> $data */
    public function createOption(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'options', $data);
    }

    /** @return array<string,mixed>|null */
    public function optionValue(int $optionId, string $slug): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %soption_values WHERE option_id = ? AND slug = ?', $this->db->prefix()),
            [$optionId, $slug]
        );
    }

    /** @param array<string,mixed> $data */
    public function createOptionValue(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'option_values', $data);
    }

    /** @return array<string,mixed>|null */
    public function optionValueById(int $id): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %soption_values WHERE id = ?', $this->db->prefix()),
            [$id]
        );
    }

    public function attachOptionToProduct(int $productId, int $optionId, int $sortOrder, bool $isMediaOption): void
    {
        $this->db->execute(
            sprintf(
                'INSERT INTO %sproduct_options (product_id, option_id, sort_order, is_media_option)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), is_media_option = VALUES(is_media_option)',
                $this->db->prefix()
            ),
            [$productId, $optionId, $sortOrder, $isMediaOption ? 1 : 0]
        );
    }

    /** @return list<array<string,mixed>> */
    public function productOptions(int $productId): array
    {
        return $this->db->select(
            sprintf(
                'SELECT o.*, po.sort_order AS product_sort_order, po.is_media_option
                   FROM %sproduct_options po
                   JOIN %soptions o ON o.id = po.option_id
                  WHERE po.product_id = ?
                  ORDER BY po.sort_order ASC, o.id ASC',
                $this->db->prefix(),
                $this->db->prefix()
            ),
            [$productId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function optionValues(int $optionId): array
    {
        return $this->db->select(
            sprintf('SELECT * FROM %soption_values WHERE option_id = ? ORDER BY sort_order ASC, id ASC', $this->db->prefix()),
            [$optionId]
        );
    }

    /** @param array<string,mixed> $data */
    public function createVariant(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'variants', $data);
    }

    /** @param array<string,mixed> $data */
    public function updateVariant(int $variantId, array $data): void
    {
        $data['updated_at'] = $this->clock->nowString();
        $this->db->update($this->db->prefix() . 'variants', $data, ['id' => $variantId]);
    }

    /** @return array<string,mixed>|null */
    public function variant(int $variantId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %svariants WHERE id = ?', $this->db->prefix()),
            [$variantId]
        );
    }

    /** @return array<string,mixed>|null */
    public function variantBySku(string $sku): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %svariants WHERE sku = ?', $this->db->prefix()),
            [$sku]
        );
    }

    /** @return list<array<string,mixed>> */
    public function variants(int $productId, bool $includeArchived = false): array
    {
        $sql = sprintf('SELECT * FROM %svariants WHERE product_id = ?', $this->db->prefix());
        if (!$includeArchived) {
            $sql .= " AND status = 'active'";
        }

        return $this->db->select($sql . ' ORDER BY sort_order ASC, id ASC', [$productId]);
    }

    /** @return list<int> option value ids attached to a variant */
    public function variantOptionValueIds(int $variantId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['option_value_id'],
            $this->db->select(
                sprintf('SELECT option_value_id FROM %svariant_option_values WHERE variant_id = ? ORDER BY option_value_id', $this->db->prefix()),
                [$variantId]
            )
        );
    }

    public function attachVariantOptionValue(int $variantId, int $optionValueId): void
    {
        $this->db->execute(
            sprintf(
                'INSERT IGNORE INTO %svariant_option_values (variant_id, option_value_id) VALUES (?, ?)',
                $this->db->prefix()
            ),
            [$variantId, $optionValueId]
        );
    }

    public function addMedia(int $productId, int $attachmentId, ?int $optionValueId, int $sortOrder): int
    {
        return $this->db->insert($this->db->prefix() . 'product_media', [
            'product_id' => $productId,
            'attachment_id' => $attachmentId,
            'option_value_id' => $optionValueId,
            'sort_order' => $sortOrder,
            'is_swatch_source' => 0,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function media(int $productId): array
    {
        return $this->db->select(
            sprintf('SELECT * FROM %sproduct_media WHERE product_id = ? ORDER BY sort_order ASC, id ASC', $this->db->prefix()),
            [$productId]
        );
    }

    /** @param array<string,mixed> $data */
    public function createCategory(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'categories', $data);
    }

    public function categorySlugExists(string $slug): bool
    {
        return (bool) $this->db->scalar(
            sprintf('SELECT 1 FROM %scategories WHERE slug = ? LIMIT 1', $this->db->prefix()),
            [$slug]
        );
    }

    /** @return array<string,mixed>|null */
    public function category(int $categoryId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %scategories WHERE id = ?', $this->db->prefix()),
            [$categoryId]
        );
    }

    /** @param array<string,mixed> $data */
    public function createPackUnit(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'pack_units', $data);
    }

    /** @return array<string,mixed>|null */
    public function packUnit(int $packUnitId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %spack_units WHERE id = ?', $this->db->prefix()),
            [$packUnitId]
        );
    }

    /**
     * Search products by name, tags or variant SKU/barcode (§11.2 item search).
     *
     * @return list<array<string,mixed>>
     */
    public function search(string $query, int $limit = 20): array
    {
        $prefix = $this->db->prefix();
        $like = '%' . $query . '%';

        return $this->db->select(
            sprintf(
                "SELECT DISTINCT p.* FROM %sproducts p
                   LEFT JOIN %svariants v ON v.product_id = p.id
                  WHERE p.status = 'active'
                    AND (p.name LIKE ? OR p.tags LIKE ? OR v.sku = ? OR v.barcode = ?)
                  ORDER BY p.name ASC LIMIT ?",
                $prefix,
                $prefix
            ),
            [$like, $like, $query, $query, $limit]
        );
    }

    /**
     * Products with their active variant count and available stock, for the
     * back office list (§11.3).
     *
     * @return list<array<string,mixed>>
     */
    public function listWithStock(?string $search = null, int $limit = 50): array
    {
        $prefix = $this->db->prefix();
        $sql = sprintf(
            "SELECT p.id, p.uuid, p.name, p.status, p.visibility, p.updated_at,
                    (SELECT COUNT(*) FROM %svariants v WHERE v.product_id = p.id AND v.status = 'active') AS variant_count,
                    (SELECT COALESCE(SUM(sl.on_hand - sl.reserved), 0)
                       FROM %sstock_levels sl
                       JOIN %svariants sv ON sv.id = sl.variant_id
                      WHERE sv.product_id = p.id AND sv.status = 'active') AS available,
                    (SELECT MIN(pr.amount_kobo)
                       FROM %sprices pr
                       JOIN %svariants pv ON pv.id = pr.variant_id
                      WHERE pv.product_id = p.id AND pv.status = 'active' AND pr.min_qty = 1
                        AND pr.price_list_id = (SELECT id FROM %sprice_lists WHERE is_base = 1 ORDER BY id ASC LIMIT 1)) AS price_kobo
               FROM %sproducts p
              WHERE p.status <> 'archived'",
            $prefix,
            $prefix,
            $prefix,
            $prefix,
            $prefix,
            $prefix,
            $prefix
        );
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (p.name LIKE ? OR p.tags LIKE ?)';
            $like = '%' . trim($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= ' ORDER BY p.id DESC LIMIT ?';
        $params[] = $limit;

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'uuid' => (string) $row['uuid'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'visibility' => (string) $row['visibility'],
            'variant_count' => (int) $row['variant_count'],
            'available' => (int) $row['available'],
            'price_kobo' => $row['price_kobo'] === null ? null : (int) $row['price_kobo'],
            'updated_at' => (string) $row['updated_at'],
        ], $this->db->select($sql, $params));
    }

    /**
     * Flat variant list for order building: variant, product name, base price
     * and available pieces.
     *
     * @return list<array<string,mixed>>
     */
    public function variantsForPicker(?string $search = null, int $limit = 100): array
    {
        $prefix = $this->db->prefix();
        $sql = sprintf(
            "SELECT v.id, v.sku, v.title, p.id AS product_id, p.name AS product_name,
                    (SELECT MIN(pr.amount_kobo) FROM %sprices pr
                      WHERE pr.variant_id = v.id AND pr.min_qty = 1
                        AND pr.price_list_id = (SELECT id FROM %sprice_lists WHERE is_base = 1 ORDER BY id ASC LIMIT 1)) AS price_kobo,
                    (SELECT COALESCE(SUM(sl.on_hand - sl.reserved), 0) FROM %sstock_levels sl WHERE sl.variant_id = v.id) AS available
               FROM %svariants v
               JOIN %sproducts p ON p.id = v.product_id
              WHERE v.status = 'active' AND p.status = 'active'",
            $prefix,
            $prefix,
            $prefix,
            $prefix,
            $prefix
        );
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (p.name LIKE ? OR v.sku LIKE ? OR v.title LIKE ?)';
            $like = '%' . trim($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= ' ORDER BY p.name ASC, v.sort_order ASC, v.id ASC LIMIT ?';
        $params[] = $limit;

        return array_map(static fn (array $row): array => [
            'variant_id' => (int) $row['id'],
            'sku' => (string) $row['sku'],
            'title' => (string) $row['title'],
            'product_id' => (int) $row['product_id'],
            'product_name' => (string) $row['product_name'],
            'price_kobo' => $row['price_kobo'] === null ? null : (int) $row['price_kobo'],
            'available' => (int) $row['available'],
        ], $this->db->select($sql, $params));
    }

    /** @return list<array<string,mixed>> */
    public function productsByStatus(string $status, int $limit = 50): array
    {
        return $this->db->select(
            sprintf('SELECT * FROM %sproducts WHERE status = ? ORDER BY id DESC LIMIT ?', $this->db->prefix()),
            [$status, $limit]
        );
    }
}
