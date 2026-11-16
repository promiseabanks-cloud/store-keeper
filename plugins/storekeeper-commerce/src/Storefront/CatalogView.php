<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront;

use StoreKeeper\Commerce\Catalog\CatalogRepository;
use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Inventory\InventoryRepository;
use StoreKeeper\Commerce\Pricing\Exception\PriceNotFound;
use StoreKeeper\Commerce\Pricing\PricingRepository;
use StoreKeeper\Commerce\Pricing\PricingService;

/**
 * Read model for the storefront (§11.4, §11.5). It never writes: stock, prices and
 * visibility all come from the engine, so the website cannot drift from the shop.
 *
 * Prices resolve through PricingService with the browsing customer, so a guest and
 * an approved wholesaler looking at the same page get their own group's price. The
 * grid reads amounts in bulk (one query per page) because it shows many products;
 * the product page resolves each variant properly, including tiers and specials.
 */
final class CatalogView
{
    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly PricingService $pricing,
        private readonly PricingRepository $prices,
        private readonly InventoryRepository $inventory,
        private readonly Connection $db,
        private readonly CustomerContext $customers,
    ) {
    }

    public function shopUrl(string $search = '', int $page = 1): string
    {
        $args = [];
        if ($search !== '') {
            $args['q'] = $search;
        }
        if ($page > 1) {
            $args['pg'] = $page;
        }

        $url = home_url('/shop/');

        return $args === [] ? $url : add_query_arg($args, $url);
    }

    public function productUrl(string $slug): string
    {
        return home_url('/product/' . rawurlencode($slug) . '/');
    }

    public function categoryUrl(string $slug): string
    {
        return home_url('/product-category/' . rawurlencode($slug) . '/');
    }

    /** @return list<array{id:int,name:string,slug:string,url:string,count:int}> */
    public function categories(): array
    {
        $prefix = $this->db->prefix();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'url' => home_url('/product-category/' . rawurlencode((string) $row['slug']) . '/'),
            'count' => (int) $row['product_count'],
        ], $this->db->select(
            sprintf(
                "SELECT c.id, c.name, c.slug,
                        (SELECT COUNT(*) FROM %sproducts p
                          WHERE p.category_id = c.id AND p.status = 'active' AND p.visibility <> 'hidden') AS product_count
                   FROM %scategories c
                  ORDER BY c.sort_order ASC, c.name ASC",
                $prefix,
                $prefix
            )
        ));
    }

    /**
     * A page of the shop or a category (§22.5).
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,search:string}
     */
    public function grid(string $search = '', ?int $categoryId = null, int $page = 1, int $perPage = 12): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $prefix = $this->db->prefix();

        $where = ["p.status = 'active'", 'p.visibility IN (' . $this->visibilityList() . ')'];
        $params = [];

        if ($categoryId !== null) {
            $where[] = 'p.category_id = ?';
            $params[] = $categoryId;
        }

        $search = trim($search);
        if ($search !== '') {
            $where[] = '(p.name LIKE ? OR p.tags LIKE ? OR p.short_description LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->scalar(
            sprintf('SELECT COUNT(*) FROM %sproducts p WHERE %s', $prefix, $whereSql),
            $params
        );

        $rows = $this->db->select(
            sprintf(
                'SELECT p.*, c.name AS category_name, c.slug AS category_slug
                   FROM %sproducts p
                   LEFT JOIN %scategories c ON c.id = p.category_id
                  WHERE %s
                  ORDER BY p.id DESC
                  LIMIT ? OFFSET ?',
                $prefix,
                $prefix,
                $whereSql
            ),
            array_merge($params, [$perPage, ($page - 1) * $perPage])
        );

        return [
            'items' => $this->cards($rows),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'search' => $search,
        ];
    }

    /**
     * Everything one product page needs (§11.4): options and their values, every
     * variant with stock and the customer's price, the galleries per media value
     * (sent up front so switching a swatch needs no request), and the current
     * selection from the query string.
     *
     * @param array<string,string> $selection option slug => value slug
     * @return array<string,mixed>|null
     */
    public function product(string $slug, array $selection = []): ?array
    {
        $product = $this->catalog->productBySlug($slug);
        if ($product === null || (string) $product['status'] !== 'active') {
            return null;
        }

        if (!$this->isVisible((string) $product['visibility'])) {
            return null;
        }

        $productId = (int) $product['id'];
        $customer = $this->customers->current();
        $options = $this->catalog->productOptions($productId);

        $variants = [];
        $variantIds = [];
        foreach ($this->catalog->variants($productId) as $variant) {
            $variantId = (int) $variant['id'];
            $valueIds = $this->catalog->variantOptionValueIds($variantId);

            $amountKobo = null;
            $source = null;
            $minQty = 1;
            try {
                $resolved = $this->pricing->resolve($variantId, null, 1, $customer);
                $amountKobo = $resolved->unitAmount->minor();
                $source = $resolved->source;
                $minQty = $resolved->minQty;
            } catch (PriceNotFound) {
                // No price for this combination: shown as "Ask for price", never guessed.
            }

            $variants[$variantId] = [
                'id' => $variantId,
                'title' => (string) $variant['title'],
                'sku' => (string) $variant['sku'],
                'value_ids' => $valueIds,
                'amount_kobo' => $amountKobo,
                'price_label' => $amountKobo === null ? 'Ask for price' : Format::price($amountKobo),
                'source' => $source,
                'min_qty' => $minQty,
            ];
            $variantIds[] = $variantId;
        }

        $stock = $this->inventory->availableOnlineMany($variantIds);
        foreach ($variants as $variantId => $variant) {
            $available = $stock[$variantId] ?? 0;
            $variants[$variantId]['available'] = $available;
            $variants[$variantId]['in_stock'] = $available > 0;
            $variants[$variantId]['stock_label'] = Format::stock($available);
        }

        $values = [];
        $optionRows = [];
        foreach ($options as $option) {
            $optionId = (int) $option['id'];
            $valueRows = [];
            foreach ($this->catalog->optionValues($optionId) as $value) {
                $valueId = (int) $value['id'];
                $usable = false;
                foreach ($variants as $variant) {
                    if (in_array($valueId, $variant['value_ids'], true)) {
                        $usable = true;
                        break;
                    }
                }
                if (!$usable) {
                    continue;
                }

                $valueRows[] = [
                    'id' => $valueId,
                    'value' => (string) $value['value'],
                    'slug' => (string) $value['slug'],
                    'color_hex' => $value['color_hex'] !== null ? (string) $value['color_hex'] : null,
                    'image' => $this->image((int) ($value['image_id'] ?? 0), 'thumbnail'),
                ];
                $values[$valueId] = (string) $value['slug'];
            }

            $optionRows[] = [
                'id' => $optionId,
                'name' => (string) $option['name'],
                'slug' => (string) $option['slug'],
                'display' => (string) $option['display'],
                'is_media' => (int) $option['is_media_option'] === 1,
                'values' => $valueRows,
            ];
        }

        $selection = $this->resolveSelection($optionRows, $variants, $selection);
        $selectedVariantId = $this->matchVariant($optionRows, $variants, $selection);
        $selected = $selectedVariantId !== null ? $variants[$selectedVariantId] : null;

        $media = $this->catalog->media($productId);
        $photosByValue = [];
        $general = [];
        foreach ($media as $row) {
            $optionValueId = $row['option_value_id'] === null ? 0 : (int) $row['option_value_id'];
            if ($optionValueId === 0) {
                $general[] = (int) $row['attachment_id'];
                continue;
            }
            $photosByValue[$optionValueId][] = (int) $row['attachment_id'];
        }

        $gallery = $this->galleryFor($optionRows, $photosByValue, $general, $selection);
        $category = $product['category_id'] !== null ? $this->catalog->category((int) $product['category_id']) : null;

        return [
            'id' => $productId,
            'name' => (string) $product['name'],
            'slug' => (string) $product['slug'],
            'url' => $this->productUrl((string) $product['slug']),
            'description' => (string) ($product['description'] ?? ''),
            'short_description' => (string) ($product['short_description'] ?? ''),
            'category_id' => $category === null ? null : (int) $category['id'],
            'category' => $category === null ? null : [
                'name' => (string) $category['name'],
                'slug' => (string) $category['slug'],
                'url' => $this->categoryUrl((string) $category['slug']),
            ],
            'options' => $optionRows,
            'variants' => $variants,
            'selection' => $selection,
            'selected_variant_id' => $selectedVariantId,
            'price' => $selected === null ? null : [
                'amount_kobo' => $selected['amount_kobo'],
                'label' => $selected['price_label'],
                'source' => $selected['source'],
                'min_qty' => $selected['min_qty'],
            ],
            'available' => $selected === null ? 0 : $selected['available'],
            'stock_label' => $selected === null ? 'Out of stock' : $selected['stock_label'],
            'in_stock' => $selected !== null && $selected['in_stock'],
            'gallery' => $gallery,
            'general_photos' => array_values(array_filter(array_map(fn (int $id): ?array => $this->image($id, 'large'), $general))),
            'photos_by_value' => array_map(
                fn (array $ids): array => array_values(array_filter(array_map(fn (int $id): ?array => $this->image($id, 'large'), $ids))),
                $photosByValue
            ),
            'seo' => [
                'title' => (string) ($product['seo_title'] ?: $product['name']),
                'description' => (string) ($product['seo_description'] ?: $product['short_description'] ?: ''),
                'image' => $gallery[0]['src'] ?? null,
            ],
        ];
    }

    /**
     * Other products in the same category, for the bottom of a product page.
     *
     * @return list<array<string,mixed>>
     */
    public function related(int $productId, ?int $categoryId, int $limit = 4): array
    {
        if ($categoryId === null) {
            return [];
        }

        $rows = $this->db->select(
            sprintf(
                "SELECT p.*, c.name AS category_name, c.slug AS category_slug
                   FROM %sproducts p
                   LEFT JOIN %scategories c ON c.id = p.category_id
                  WHERE p.status = 'active' AND p.id <> ? AND p.category_id = ?
                    AND p.visibility IN (" . $this->visibilityList() . ')
                  ORDER BY p.id DESC LIMIT ?',
                $this->db->prefix(),
                $this->db->prefix()
            ),
            [$productId, $categoryId, $limit]
        );

        return $this->cards($rows);
    }

    /** @return array<string,mixed>|null a row from {p}categories */
    public function categoryBySlug(string $slug): ?array
    {
        return $this->catalog->categoryBySlug($slug);
    }

    /** The group whose prices this visitor sees: their own, else the default group. */
    public function priceListId(): ?int
    {
        $customer = $this->customers->current();
        if ($customer !== null && ($customer['approval_status'] ?? 'approved') === 'approved' && isset($customer['group_id'])) {
            $group = $this->prices->group((int) $customer['group_id']);
            if ($group !== null) {
                return (int) $group['price_list_id'];
            }
        }

        $default = $this->prices->defaultGroup();
        if ($default !== null) {
            return (int) $default['price_list_id'];
        }

        $base = $this->prices->basePriceList();

        return $base === null ? null : (int) $base['id'];
    }

    public function isSignedIn(): bool
    {
        return $this->customers->isSignedIn();
    }

    /** Cache key ingredient: the group is what makes two responses differ. */
    public function pricingSignature(): string
    {
        return 'guest-' . (string) $this->priceListId();
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function cards(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $productIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $variants = $this->variantsFor($productIds);
        $variantIds = [];
        foreach ($variants as $list) {
            foreach ($list as $variant) {
                $variantIds[] = (int) $variant['id'];
            }
        }

        $listId = $this->priceListId();
        $amounts = $listId === null ? [] : $this->prices->displayAmounts($listId, $variantIds);
        $stock = $this->inventory->availableOnlineMany($variantIds);
        $media = $this->mediaFor($productIds);
        $swatches = $this->swatchesFor($productIds);

        $cards = [];
        foreach ($rows as $row) {
            $productId = (int) $row['id'];
            $productVariants = $variants[$productId] ?? [];
            $available = 0;
            $amountsForProduct = [];
            foreach ($productVariants as $variant) {
                $available += $stock[(int) $variant['id']] ?? 0;
                if (isset($amounts[(int) $variant['id']])) {
                    $amountsForProduct[] = $amounts[(int) $variant['id']];
                }
            }

            $from = $amountsForProduct === [] ? null : min($amountsForProduct);
            $to = $amountsForProduct === [] ? null : max($amountsForProduct);

            // Prefer a photo tied to a colour value: a card should show the piece,
            // not the studio shot, and every option has one (§11.4).
            $productMedia = $media[$productId] ?? [];
            usort($productMedia, static fn (array $a, array $b): int => ($a['option_value_id'] === null ? 1 : 0) <=> ($b['option_value_id'] === null ? 1 : 0));

            $image = null;
            foreach ($productMedia as $mediaRow) {
                $image = $this->image((int) $mediaRow['attachment_id'], 'medium_large');
                if ($image !== null) {
                    break;
                }
            }

            $cards[] = [
                'id' => $productId,
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'url' => $this->productUrl((string) $row['slug']),
                'category' => $row['category_name'] === null ? null : [
                    'name' => (string) $row['category_name'],
                    'url' => $this->categoryUrl((string) $row['category_slug']),
                ],
                'image' => $image,
                'initials' => $this->initials((string) $row['name']),
                'price' => [
                    'kobo' => $from,
                    'label' => $from === null ? 'Ask for price' : Format::price($from),
                    'is_range' => $to !== null && $from !== null && $to > $from,
                ],
                'available' => $available,
                'in_stock' => $available > 0,
                'swatches' => $swatches[$productId] ?? [],
            ];
        }

        return $cards;
    }

    /**
     * @param list<int> $productIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function variantsFor(array $productIds): array
    {
        $rows = $this->db->select(
            sprintf(
                "SELECT id, product_id, title, sku, sort_order
                   FROM %svariants
                  WHERE product_id IN (%s) AND status = 'active'
                  ORDER BY sort_order ASC, id ASC",
                $this->db->prefix(),
                $this->placeholders($productIds)
            ),
            $productIds
        );

        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[(int) $row['product_id']][] = $row;
        }

        return $byProduct;
    }

    /**
     * @param list<int> $productIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function mediaFor(array $productIds): array
    {
        $rows = $this->db->select(
            sprintf(
                'SELECT id, product_id, attachment_id, option_value_id, sort_order
                   FROM %sproduct_media
                  WHERE product_id IN (%s)
                  ORDER BY sort_order ASC, id ASC',
                $this->db->prefix(),
                $this->placeholders($productIds)
            ),
            $productIds
        );

        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[(int) $row['product_id']][] = $row;
        }

        return $byProduct;
    }

    /**
     * The colour chips a card shows: the values of each product's media option.
     *
     * @param list<int> $productIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function swatchesFor(array $productIds): array
    {
        $rows = $this->db->select(
            sprintf(
                "SELECT po.product_id, ov.id, ov.value, ov.slug, ov.color_hex, ov.image_id, ov.sort_order
                   FROM %sproduct_options po
                   JOIN %soption_values ov ON ov.option_id = po.option_id
                   JOIN %svariant_option_values vov ON vov.option_value_id = ov.id
                   JOIN %svariants v ON v.id = vov.variant_id AND v.product_id = po.product_id AND v.status = 'active'
                  WHERE po.product_id IN (%s) AND po.is_media_option = 1
                  ORDER BY po.product_id ASC, ov.sort_order ASC, ov.id ASC",
                $this->db->prefix(),
                $this->db->prefix(),
                $this->db->prefix(),
                $this->db->prefix(),
                $this->placeholders($productIds)
            ),
            $productIds
        );

        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[(int) $row['product_id']][] = [
                'slug' => (string) $row['slug'],
                'value' => (string) $row['value'],
                'color_hex' => $row['color_hex'] !== null ? (string) $row['color_hex'] : null,
                'image' => $this->image((int) ($row['image_id'] ?? 0), 'thumbnail'),
            ];
        }

        return $byProduct;
    }

    /**
     * @param list<array<string,mixed>> $options
     * @param array<int,array<string,mixed>> $variants
     * @param array<string,string> $requested
     * @return array<string,string>
     */
    private function resolveSelection(array $options, array $variants, array $requested): array
    {
        $selection = [];

        foreach ($options as $option) {
            $wanted = $requested[$option['slug']] ?? '';
            foreach ($option['values'] as $value) {
                if ($value['slug'] === $wanted) {
                    $selection[$option['slug']] = $value['slug'];
                }
            }
        }

        // A fully specified combination is honoured even when it has no variant:
        // the brief shows it as out of stock instead of silently swapping options.
        $complete = $options !== [] && count($selection) === count($options);
        if ($complete) {
            return $selection;
        }

        // Default: the first combination that is in stock, else the first that has a price.
        $fallback = null;
        foreach ($variants as $variant) {
            if ($variant['in_stock'] && $variant['amount_kobo'] !== null) {
                $fallback = $variant;
                break;
            }
        }
        if ($fallback === null) {
            foreach ($variants as $variant) {
                if ($variant['amount_kobo'] !== null) {
                    $fallback = $variant;
                    break;
                }
            }
        }
        if ($fallback === null) {
            $first = reset($variants);
            $fallback = $first === false ? null : $first;
        }

        if ($fallback === null) {
            return [];
        }

        $byValueId = [];
        foreach ($options as $option) {
            foreach ($option['values'] as $value) {
                $byValueId[$value['id']] = ['option' => $option['slug'], 'value' => $value['slug']];
            }
        }

        foreach ($fallback['value_ids'] as $valueId) {
            if (isset($byValueId[$valueId])) {
                $selection[$byValueId[$valueId]['option']] = $byValueId[$valueId]['value'];
            }
        }

        return $selection;
    }

    /**
     * @param list<array<string,mixed>> $options
     * @param array<int,array<string,mixed>> $variants
     * @param array<string,string> $selection
     */
    private function matchVariant(array $options, array $variants, array $selection): ?int
    {
        if ($variants === []) {
            return null;
        }

        $wantedValueIds = [];
        foreach ($options as $option) {
            if (!isset($selection[$option['slug']])) {
                continue;
            }
            foreach ($option['values'] as $value) {
                if ($value['slug'] === $selection[$option['slug']]) {
                    $wantedValueIds[] = $value['id'];
                }
            }
        }

        $best = null;
        foreach ($variants as $variantId => $variant) {
            if ($wantedValueIds !== [] && array_diff($wantedValueIds, $variant['value_ids']) !== []) {
                continue;
            }

            if ($best === null) {
                $best = $variantId;
                continue;
            }

            $bestVariant = $variants[$best];
            $score = static fn (array $v): int => ($v['in_stock'] ? 2 : 0) + ($v['amount_kobo'] !== null ? 1 : 0);
            if ($score($variant) > $score($bestVariant)) {
                $best = $variantId;
            }
        }

        return $best;
    }

    /**
     * Photos of the selected media value first, then the product's general photos
     * (§11.4). Value photos are also returned per value so a swatch switch is instant.
     *
     * @param list<array<string,mixed>> $options
     * @param array<int,list<int>> $photosByValue
     * @param list<int> $general
     * @param array<string,string> $selection
     * @return list<array<string,mixed>>
     */
    private function galleryFor(array $options, array $photosByValue, array $general, array $selection): array
    {
        $attachmentIds = [];

        foreach ($options as $option) {
            if (!$option['is_media']) {
                continue;
            }
            foreach ($option['values'] as $value) {
                if (($selection[$option['slug']] ?? '') === $value['slug']) {
                    $attachmentIds = array_merge($attachmentIds, $photosByValue[$value['id']] ?? []);
                }
            }
        }

        $attachmentIds = array_merge($attachmentIds, $general);
        if ($attachmentIds === []) {
            foreach ($photosByValue as $ids) {
                $attachmentIds = array_merge($attachmentIds, $ids);
            }
        }

        $gallery = [];
        foreach (array_unique($attachmentIds) as $attachmentId) {
            $image = $this->image($attachmentId, 'large');
            if ($image !== null) {
                $gallery[] = $image;
            }
        }

        return $gallery;
    }

    private function visibilityList(): string
    {
        $customer = $this->customers->current();
        $wholesale = false;

        if ($customer !== null && isset($customer['group_id'])) {
            $group = $this->prices->group((int) $customer['group_id']);
            $wholesale = $group !== null && (string) $group['type'] === 'wholesale';
        }

        // A wholesale buyer must still see retail-only stock; the brief's rule is
        // that one storefront serves both groups (§11.5).
        return $wholesale ? "'all','wholesale_only','retail_only'" : "'all','retail_only'";
    }

    private function isVisible(string $visibility): bool
    {
        return $visibility !== 'hidden'
            && in_array("'" . $visibility . "'", explode(',', $this->visibilityList()), true);
    }

    /** @param list<int> $ids */
    private function placeholders(array $ids): string
    {
        return implode(',', array_fill(0, max(1, count($ids)), '?'));
    }

    private function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $initials === '' ? '·' : $initials;
    }

    /**
     * WordPress attachment to a plain array. Guarded because the storefront read
     * model is also exercised by tests that do not load WordPress.
     *
     * @return array{id:int,src:string,srcset:string,alt:string,width:int,height:int}|null
     */
    private function image(int $attachmentId, string $size): ?array
    {
        if ($attachmentId <= 0 || !function_exists('wp_get_attachment_image_url')) {
            return null;
        }

        $src = wp_get_attachment_image_url($attachmentId, $size);
        if (!is_string($src) || $src === '') {
            return null;
        }

        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($attachmentId) : [];
        $srcset = function_exists('wp_get_attachment_image_srcset') ? wp_get_attachment_image_srcset($attachmentId, $size) : '';

        return [
            'id' => $attachmentId,
            'src' => $src,
            'srcset' => is_string($srcset) ? $srcset : '',
            'alt' => function_exists('get_post_meta') ? (string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true) : '',
            'width' => (int) ($meta['width'] ?? 0),
            'height' => (int) ($meta['height'] ?? 0),
        ];
    }
}
