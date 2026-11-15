<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Catalog;

use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Audit\AuditLogger;
use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Exception\NotFound;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Hooks\Hooks;
use StoreKeeper\Commerce\Core\Money;
use StoreKeeper\Commerce\Core\Strings;
use StoreKeeper\Commerce\Core\Uuid;
use StoreKeeper\Commerce\Inventory\InventoryService;
use StoreKeeper\Commerce\Pricing\PricingRepository;

/**
 * Catalog engine (R3, R4): category-agnostic products whose variants come from
 * merchant-defined options, photos attached to option values, quick add,
 * variant grid and variant rebuild (§8.1, §11.3, §11.4).
 */
final class CatalogService
{
    public function __construct(
        private readonly Connection $db,
        private readonly CatalogRepository $catalog,
        private readonly InventoryService $inventory,
        private readonly PricingRepository $prices,
        private readonly Hooks $hooks,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Create a product with its options, variants, prices and opening stock.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createProduct(array $input, Actor $actor): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new ValidationFailed('A product name is required.', ['name' => 'Required.']);
        }

        $options = $this->normaliseOptionsInput($input['options'] ?? []);
        $variantsInput = $this->normaliseVariantsInput($input['variants'] ?? []);

        if ($options === [] && $variantsInput === []) {
            $variantsInput = [['values' => [], 'qty' => $input['stock'] ?? 0]];
        }

        $status = in_array($input['status'] ?? 'active', ['draft', 'active', 'archived'], true)
            ? (string) ($input['status'] ?? 'active')
            : 'active';
        $productLevelPrice = isset($input['price_kobo']) ? (int) $input['price_kobo'] : null;
        $locationId = isset($input['location_id']) ? (int) $input['location_id'] : null;

        [$productId, $createdVariants] = $this->db->transaction(function () use (
            $name,
            $input,
            $options,
            $variantsInput,
            $status,
            $productLevelPrice,
            $locationId,
            $actor,
        ): array {
            $slug = Strings::uniqueSlug($name, fn (string $candidate): bool => $this->catalog->productSlugExists($candidate), 'product');
            $now = $this->clock->nowString();

            $productId = $this->catalog->createProduct([
                'uuid' => Uuid::v4(),
                'name' => $name,
                'slug' => $slug,
                'description' => $input['description'] ?? null,
                'short_description' => $input['short_description'] ?? null,
                'category_id' => $input['category_id'] ?? null,
                'status' => $status,
                'visibility' => $input['visibility'] ?? 'all',
                'base_unit_label' => $input['base_unit_label'] ?? 'piece',
                'track_stock' => isset($input['track_stock']) && !$input['track_stock'] ? 0 : 1,
                'allow_backorder' => !empty($input['allow_backorder']) ? 1 : 0,
                'tags' => $input['tags'] ?? null,
                'seo_title' => $input['seo_title'] ?? null,
                'seo_description' => $input['seo_description'] ?? null,
                'created_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $optionDefinitions = $this->resolveOptions($productId, $options);
            $created = $this->createVariantGrid($productId, $optionDefinitions, $variantsInput);

            foreach ($created as $index => $variant) {
                $priceKobo = $variant['input_price_kobo'] ?? $productLevelPrice;
                if ($priceKobo !== null) {
                    $listId = $this->basePriceListId();
                    // $wpdb returns numeric columns as strings; the engine is strictly typed.
                    $this->prices->upsertPrice($listId, (int) $variant['id'], null, 1, $priceKobo);
                }
            }

            return [$productId, $created];
        });

        // Opening stock runs after the catalog transaction so the ledger's own
        // transaction and post-commit hooks stay honest (§9.3).
        $variants = [];
        foreach ($createdVariants as $variant) {
            $qty = (int) ($variant['input_qty'] ?? 0);
            if ($qty > 0 && $locationId !== null) {
                $this->inventory->opening($variant['id'], $locationId, $qty, $actor, 'Product created');
            }
            unset($variant['input_price_kobo'], $variant['input_qty']);
            $variants[] = $this->variantWithValues($variant, $options);
        }

        $product = $this->catalog->product($productId) ?? [];
        $this->audit->log($actor, 'product.created', 'product', $productId, null, [
            'name' => $name,
            'variants' => count($variants),
        ]);
        $this->hooks->do('catalog/product_created', ['product_id' => $productId, 'product' => $product]);

        return [
            'product' => $product,
            'variants' => $variants,
            'media' => $this->catalog->media($productId),
        ];
    }

    /**
     * Quick add on a phone: name, price, quantity and optional photo (§11.3).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function quickAdd(array $input, Actor $actor): array
    {
        $input['options'] = [];
        $input['status'] = $input['status'] ?? 'active';

        return $this->createProduct($input, $actor);
    }

    /**
     * Rebuild the variant grid after options/values change (§8.1 rules).
     * Variants that no longer match are archived, never deleted, so ledger
     * history and past orders stay intact.
     *
     * @return array{created:list<array<string,mixed>>,archived:list<int>,kept:list<int>}
     */
    public function rebuildVariants(int $productId, Actor $actor): array
    {
        $product = $this->catalog->product($productId);
        if ($product === null) {
            throw new NotFound('product', $productId);
        }

        return $this->db->transaction(function () use ($productId, $product, $actor): array {
            $options = [];
            foreach ($this->catalog->productOptions($productId) as $option) {
                $options[] = [
                    'id' => (int) $option['id'],
                    'name' => (string) $option['name'],
                    'slug' => (string) $option['slug'],
                    'display' => (string) $option['display'],
                    'media' => (int) $option['is_media_option'] === 1,
                    'values' => array_map(
                        static fn (array $value): array => ['id' => (int) $value['id'], 'value' => (string) $value['value'], 'slug' => (string) $value['slug']],
                        $this->catalog->optionValues((int) $option['id'])
                    ),
                ];
            }

            $combinations = $this->combinations($options);
            $existing = [];
            foreach ($this->catalog->variants($productId, true) as $variant) {
                $key = $this->combinationKey($this->catalog->variantOptionValueIds((int) $variant['id']));
                $existing[$key] = $variant;
            }

            $created = [];
            $kept = [];

            foreach ($combinations as $combination) {
                $key = $this->combinationKey(array_column($combination, 'id'));
                if (isset($existing[$key])) {
                    if ($existing[$key]['status'] !== 'active') {
                        $this->catalog->updateVariant((int) $existing[$key]['id'], ['status' => 'active']);
                    }
                    $kept[] = (int) $existing[$key]['id'];
                    unset($existing[$key]);
                    continue;
                }

                $variant = $this->createVariantRow(
                    $productId,
                    $product,
                    $combination,
                    count($kept) + count($created)
                );
                $created[] = $variant;
            }

            $archived = [];
            foreach ($existing as $variant) {
                $this->catalog->updateVariant((int) $variant['id'], ['status' => 'archived']);
                $archived[] = (int) $variant['id'];
            }

            $this->audit->log($actor, 'product.variants_rebuilt', 'product', $productId, null, [
                'created' => array_column($created, 'id'),
                'archived' => $archived,
                'kept' => $kept,
            ]);

            return ['created' => $created, 'archived' => $archived, 'kept' => $kept];
        });
    }

    /**
     * Attach a photo to the product, optionally scoped to one option value (R4).
     */
    public function assignMedia(
        int $productId,
        int $attachmentId,
        ?int $optionValueId,
        Actor $actor,
        int $sortOrder = 0,
    ): array {
        if ($this->catalog->product($productId) === null) {
            throw new NotFound('product', $productId);
        }

        if ($optionValueId !== null && $this->catalog->optionValueById($optionValueId) === null) {
            throw new NotFound('option value', $optionValueId);
        }

        $mediaId = $this->catalog->addMedia($productId, $attachmentId, $optionValueId, $sortOrder);
        $this->audit->log($actor, 'product.media_attached', 'product', $productId, null, [
            'attachment_id' => $attachmentId,
            'option_value_id' => $optionValueId,
        ]);

        return ['id' => $mediaId, 'option_value_id' => $optionValueId, 'attachment_id' => $attachmentId];
    }

    public function setStatus(int $productId, string $status, Actor $actor): array
    {
        if (!in_array($status, ['draft', 'active', 'archived'], true)) {
            throw new ValidationFailed('Unknown product status.', ['status' => 'draft, active or archived.']);
        }

        $product = $this->catalog->product($productId);
        if ($product === null) {
            throw new NotFound('product', $productId);
        }

        $this->catalog->updateProduct($productId, ['status' => $status]);

        if ($status === 'archived') {
            $this->db->execute(
                sprintf("UPDATE %svariants SET status = 'archived' WHERE product_id = ?", $this->db->prefix()),
                [$productId]
            );
        }

        $this->audit->log($actor, 'product.status_changed', 'product', $productId, ['status' => $product['status']], ['status' => $status]);

        return $this->catalog->product($productId) ?? [];
    }

    public function archive(int $productId, Actor $actor): array
    {
        return $this->setStatus($productId, 'archived', $actor);
    }

    /** @param array<string,mixed> $input */
    public function createCategory(array $input, Actor $actor): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new ValidationFailed('A category name is required.', ['name' => 'Required.']);
        }

        $slug = Strings::uniqueSlug($name, fn (string $candidate): bool => $this->catalog->categorySlugExists($candidate), 'category');
        $now = $this->clock->nowString();

        $categoryId = $this->catalog->createCategory([
            'uuid' => Uuid::v4(),
            'parent_id' => $input['parent_id'] ?? null,
            'name' => $name,
            'slug' => $slug,
            'image_id' => $input['image_id'] ?? null,
            'sort_order' => $input['sort_order'] ?? 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit->log($actor, 'category.created', 'category', $categoryId, null, ['name' => $name]);

        return $this->catalog->category($categoryId) ?? [];
    }

    /** Store-wide or product-specific pack units, e.g. Dozen = 12 (§8.1). */
    public function addPackUnit(string $name, int $pieces, ?int $productId = null): array
    {
        if ($pieces < 2) {
            throw new ValidationFailed('A pack unit must contain more than one piece.', ['pieces' => 'Must be 2 or more.']);
        }

        $packUnitId = $this->catalog->createPackUnit([
            'product_id' => $productId,
            'name' => $name,
            'pieces' => $pieces,
            'sort_order' => 0,
        ]);

        return $this->catalog->packUnit($packUnitId) ?? [];
    }

    public function addOption(string $name, string $display = 'label'): array
    {
        if (!in_array($display, ['color', 'image', 'label', 'dropdown'], true)) {
            throw new ValidationFailed('Unknown option display type.', ['display' => 'color, image, label or dropdown.']);
        }

        $slug = Strings::uniqueSlug($name, fn (string $candidate): bool => $this->catalog->optionBySlug($candidate) !== null, 'option');
        $optionId = $this->catalog->createOption([
            'name' => $name,
            'slug' => $slug,
            'display' => $display,
            'sort_order' => 0,
            'created_at' => $this->clock->nowString(),
        ]);

        return ['id' => $optionId, 'name' => $name, 'slug' => $slug, 'display' => $display];
    }

    public function addOptionValue(int $optionId, string $value, ?string $colorHex = null, ?int $imageId = null): array
    {
        $slug = Strings::uniqueSlug(
            $value,
            fn (string $candidate): bool => $this->catalog->optionValue($optionId, $candidate) !== null,
            'value'
        );

        $valueId = $this->catalog->createOptionValue([
            'option_id' => $optionId,
            'value' => $value,
            'slug' => $slug,
            'color_hex' => $colorHex,
            'image_id' => $imageId,
            'sort_order' => 0,
        ]);

        return $this->catalog->optionValueById($valueId) ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function search(string $query, int $limit = 20): array
    {
        return $this->catalog->search(trim($query), $limit);
    }

    /** @return array<string,mixed>|null */
    public function productByUuid(string $uuid): ?array
    {
        return $this->catalog->productByUuid($uuid);
    }

    /** @return array<string,mixed>|null */
    public function variantBySku(string $sku): ?array
    {
        return $this->catalog->variantBySku($sku);
    }

    /** @return list<array<string,mixed>> */
    public function variants(int $productId): array
    {
        return $this->catalog->variants($productId);
    }

    /**
     * @param list<array{name:string,slug?:string,display?:string,media?:bool,values:list<array{value:string,slug?:string,color_hex?:string|null,image_id?:int|null}>}> $options
     * @return list<array{id:int,name:string,slug:string,display:string,media:bool,values:list<array{id:int,value:string,slug:string}>}>
     */
    private function resolveOptions(int $productId, array $options): array
    {
        $resolved = [];
        $mediaAssigned = false;

        foreach ($options as $position => $option) {
            $existing = $this->catalog->optionBySlug($option['slug']);
            $optionId = $existing === null
                ? $this->catalog->createOption([
                    'name' => $option['name'],
                    'slug' => $option['slug'],
                    'display' => $option['display'],
                    'sort_order' => $position,
                    'created_at' => $this->clock->nowString(),
                ])
                : (int) $existing['id'];

            $isMedia = $option['media'] || (!$mediaAssigned && in_array($option['display'], ['color', 'image'], true));
            $mediaAssigned = $mediaAssigned || $isMedia;
            $this->catalog->attachOptionToProduct($productId, $optionId, $position, $isMedia);

            $values = [];
            foreach ($option['values'] as $sortOrder => $value) {
                $existingValue = $this->catalog->optionValue($optionId, $value['slug']);
                $valueId = $existingValue === null
                    ? $this->catalog->createOptionValue([
                        'option_id' => $optionId,
                        'value' => $value['value'],
                        'slug' => $value['slug'],
                        'color_hex' => $value['color_hex'],
                        'image_id' => $value['image_id'],
                        'sort_order' => $sortOrder,
                    ])
                    : (int) $existingValue['id'];

                $values[] = ['id' => $valueId, 'value' => $value['value'], 'slug' => $value['slug']];
            }

            $resolved[] = [
                'id' => $optionId,
                'name' => $option['name'],
                'slug' => $option['slug'],
                'display' => $option['display'],
                'media' => $isMedia,
                'values' => $values,
            ];
        }

        return $resolved;
    }

    /**
     * @param list<array{id:int,name:string,slug:string,display:string,media:bool,values:list<array{id:int,value:string,slug:string}>}> $options
     * @param list<array<string,mixed>> $variantsInput
     * @return list<array<string,mixed>>
     */
    private function createVariantGrid(int $productId, array $options, array $variantsInput): array
    {
        $product = $this->catalog->product($productId) ?? [];
        $byCombination = [];

        foreach ($variantsInput as $variant) {
            $ids = [];
            foreach ($options as $option) {
                $selected = $variant['values'][$option['slug']] ?? null;
                if ($selected === null) {
                    continue 2;
                }

                foreach ($option['values'] as $value) {
                    if ($value['slug'] === $selected || $value['value'] === $selected) {
                        $ids[] = (int) $value['id'];
                        continue 2;
                    }
                }
            }
            $byCombination[$this->combinationKey($ids)] = $variant;
        }

        $created = [];
        $combinations = $options === [] ? [[]] : $this->combinations($options);

        foreach ($combinations as $index => $combination) {
            $key = $this->combinationKey(array_column($combination, 'id'));
            $input = $byCombination[$key] ?? [];

            $variant = $this->createVariantRow(
                $productId,
                $product,
                $combination,
                $index,
                $input
            );

            $created[] = $variant;
        }

        return $created;
    }

    /**
     * @param array<string,mixed> $product
     * @param list<array{id:int,value:string,slug:string}> $combination
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function createVariantRow(
        int $productId,
        array $product,
        array $combination,
        int $sortOrder,
        array $input = [],
    ): array {
        $title = $combination === []
            ? 'Default'
            : implode(' / ', array_column($combination, 'value'));

        $sku = trim((string) ($input['sku'] ?? ''));
        if ($sku === '') {
            $sku = $this->generateSku((string) ($product['name'] ?? 'item'));
        } elseif ($this->catalog->variantBySku($sku) !== null) {
            throw new ValidationFailed('That SKU is already used by another variant.', ['sku' => 'Must be unique.']);
        }

        $now = $this->clock->nowString();
        $variantId = $this->catalog->createVariant([
            'uuid' => Uuid::v4(),
            'product_id' => $productId,
            'sku' => $sku,
            'barcode' => $input['barcode'] ?? null,
            'title' => $title,
            'weight_grams' => $input['weight_grams'] ?? null,
            'cost_kobo' => $input['cost_kobo'] ?? null,
            'low_stock_threshold' => $input['low_stock_threshold'] ?? null,
            'status' => 'active',
            'sort_order' => $sortOrder,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($combination as $value) {
            $this->catalog->attachVariantOptionValue($variantId, (int) $value['id']);
        }

        $variant = $this->catalog->variant($variantId) ?? [];
        $variant['option_values'] = $combination;
        $variant['input_price_kobo'] = isset($input['price_kobo']) ? (int) $input['price_kobo'] : null;
        $variant['input_qty'] = isset($input['qty']) ? (int) $input['qty'] : 0;

        return $variant;
    }

    /**
     * @param list<array<string,mixed>> $options
     * @return list<list<array{id:int,value:string,slug:string}>>
     */
    private function combinations(array $options): array
    {
        $combinations = [[]];

        foreach ($options as $option) {
            $expanded = [];
            foreach ($combinations as $combination) {
                foreach ($option['values'] as $value) {
                    $expanded[] = [...$combination, $value];
                }
            }
            $combinations = $expanded;
        }

        return $combinations;
    }

    /** @param list<int> $ids */
    private function combinationKey(array $ids): string
    {
        sort($ids);

        return implode('-', $ids);
    }

    private function generateSku(string $productName): string
    {
        $base = strtoupper(Strings::initials($productName));
        do {
            $sku = $base . '-' . strtoupper(bin2hex(random_bytes(3)));
        } while ($this->catalog->variantBySku($sku) !== null);

        return $sku;
    }

    private function basePriceListId(): int
    {
        $base = $this->prices->basePriceList();
        if ($base !== null) {
            return (int) $base['id'];
        }

        return $this->prices->createList('Retail', \StoreKeeper\Commerce\Core\Brand::CURRENCY, true);
    }

    /**
     * @param array<string,mixed> $input
     * @return list<array{name:string,slug:string,display:string,media:bool,values:list<array{value:string,slug:string,color_hex:string|null,image_id:int|null}>}>
     */
    private function normaliseOptionsInput(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $normalised = [];
        foreach ($options as $option) {
            if (!is_array($option) || trim((string) ($option['name'] ?? '')) === '') {
                continue;
            }

            $name = trim((string) $option['name']);
            $values = [];
            foreach ($option['values'] ?? [] as $value) {
                if (is_array($value)) {
                    $label = trim((string) ($value['value'] ?? ''));
                    $colorHex = $value['color_hex'] ?? null;
                    $imageId = $value['image_id'] ?? null;
                } else {
                    $label = trim((string) $value);
                    $colorHex = null;
                    $imageId = null;
                }

                if ($label === '') {
                    continue;
                }

                $values[] = [
                    'value' => $label,
                    'slug' => Strings::slug((string) ($value['slug'] ?? $label)),
                    'color_hex' => is_string($colorHex) && preg_match('/^#[0-9a-fA-F]{6}$/', $colorHex) === 1 ? $colorHex : null,
                    'image_id' => $imageId === null ? null : (int) $imageId,
                ];
            }

            if ($values === []) {
                continue;
            }

            $normalised[] = [
                'name' => $name,
                'slug' => Strings::slug((string) ($option['slug'] ?? $name)),
                'display' => in_array($option['display'] ?? 'label', ['color', 'image', 'label', 'dropdown'], true)
                    ? (string) ($option['display'] ?? 'label')
                    : 'label',
                'media' => !empty($option['media']),
                'values' => $values,
            ];
        }

        return $normalised;
    }

    /**
     * @param mixed $variants
     * @return list<array<string,mixed>>
     */
    private function normaliseVariantsInput(mixed $variants): array
    {
        if (!is_array($variants)) {
            return [];
        }

        $normalised = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $values = [];
            foreach ($variant['values'] ?? [] as $optionSlug => $valueSlug) {
                $values[Strings::slug((string) $optionSlug)] = (string) $valueSlug;
            }

            $normalised[] = [
                'values' => $values,
                'sku' => $variant['sku'] ?? null,
                'barcode' => $variant['barcode'] ?? null,
                'price_kobo' => isset($variant['price_kobo']) ? (int) $variant['price_kobo'] : null,
                'cost_kobo' => isset($variant['cost_kobo']) ? (int) $variant['cost_kobo'] : null,
                'weight_grams' => isset($variant['weight_grams']) ? (int) $variant['weight_grams'] : null,
                'low_stock_threshold' => isset($variant['low_stock_threshold']) ? (int) $variant['low_stock_threshold'] : null,
                'qty' => isset($variant['qty']) ? (int) $variant['qty'] : (isset($variant['stock']) ? (int) $variant['stock'] : 0),
            ];
        }

        return $normalised;
    }

    /**
     * @param array<string,mixed> $variant
     * @param list<array<string,mixed>> $options
     * @return array<string,mixed>
     */
    private function variantWithValues(array $variant, array $options): array
    {
        if (isset($variant['option_values'])) {
            return $variant;
        }

        $values = [];
        foreach ($this->catalog->variantOptionValueIds((int) $variant['id']) as $valueId) {
            $value = $this->catalog->optionValueById($valueId);
            if ($value !== null) {
                $values[] = ['id' => (int) $value['id'], 'value' => (string) $value['value'], 'slug' => (string) $value['slug']];
            }
        }
        $variant['option_values'] = $values;

        return $variant;
    }
}
