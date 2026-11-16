<?php
/**
 * Product page: gallery, option swatches that switch photos, and the price for the
 * visitor's group (§11.4). Selecting a value is a normal link, so the page works
 * without JavaScript and a shared link keeps its selection.
 *
 * @var \StoreKeeper\Commerce\Storefront\CatalogView $view
 */

use StoreKeeper\Commerce\Storefront\Storefront;

$view = Storefront::view();
$product = Storefront::currentProduct();

if ($product === null) {
    return;
}

$selection = $product['selection'];
$selectedVariant = $product['selected_variant_id'] === null
    ? null
    : $product['variants'][$product['selected_variant_id']] ?? null;

$valueSlugById = [];
foreach ($product['options'] as $option) {
    foreach ($option['values'] as $value) {
        $valueSlugById[$value['id']] = $value['slug'];
    }
}

$photosBySlug = [];
foreach ($product['photos_by_value'] as $valueId => $images) {
    $slug = $valueSlugById[$valueId] ?? null;
    if ($slug !== null) {
        $photosBySlug[$slug] = array_map(static fn (array $image): array => [
            'src' => $image['src'],
            'srcset' => $image['srcset'],
            'alt' => $image['alt'],
        ], $images);
    }
}

$variantPayload = [];
foreach ($product['variants'] as $variant) {
    $slugs = [];
    foreach ($variant['value_ids'] as $valueId) {
        if (isset($valueSlugById[$valueId])) {
            $slugs[] = $valueSlugById[$valueId];
        }
    }
    $variantPayload[] = [
        'id' => $variant['id'],
        'values' => array_values(array_unique($slugs)),
        'price' => $variant['price_label'],
        'stock' => $variant['stock_label'],
        'in_stock' => $variant['in_stock'],
    ];
}

$related = $view->related((int) $product['id'], $product['category_id'] ?? null);

$selectionUrl = static function (string $optionSlug, string $valueSlug) use ($product): string {
    $query = $product['selection'];
    $query[$optionSlug] = $valueSlug;

    return add_query_arg($query, $product['url']);
};

get_header();
?>

<div class="skf-wrap">
    <nav class="skf-breadcrumb" aria-label="Breadcrumb">
        <a href="<?php echo esc_url(home_url('/shop/')); ?>">Shop</a>
        <?php if ($product['category'] !== null) : ?>
            <span aria-hidden="true">/</span>
            <a href="<?php echo esc_url($product['category']['url']); ?>"><?php echo esc_html($product['category']['name']); ?></a>
        <?php endif; ?>
        <span aria-hidden="true">/</span>
        <span><?php echo esc_html($product['name']); ?></span>
    </nav>

    <div class="skf-product" data-sk-product>
        <div class="skf-gallery">
            <figure class="skf-gallery__main">
                <?php $hero = $product['gallery'][0] ?? null; ?>
                <?php if ($hero !== null) : ?>
                    <img
                        data-sk-hero
                        src="<?php echo esc_url($hero['src']); ?>"
                        <?php if ($hero['srcset'] !== '') : ?>srcset="<?php echo esc_attr($hero['srcset']); ?>"<?php endif; ?>
                        alt="<?php echo esc_attr($hero['alt'] !== '' ? $hero['alt'] : $product['name']); ?>"
                        width="<?php echo esc_attr((string) max(1, $hero['width'])); ?>"
                        height="<?php echo esc_attr((string) max(1, $hero['height'])); ?>"
                        fetchpriority="high"
                        decoding="async"
                    />
                <?php else : ?>
                    <span class="skf-gallery__placeholder"><?php echo esc_html(mb_strtoupper(mb_substr($product['name'], 0, 2))); ?></span>
                <?php endif; ?>
            </figure>

            <?php if (count($product['gallery']) > 1) : ?>
                <ul class="skf-gallery__thumbs" data-sk-thumbs>
                    <?php foreach ($product['gallery'] as $index => $image) : ?>
                        <li>
                            <button
                                type="button"
                                class="skf-thumb<?php echo $index === 0 ? ' is-active' : ''; ?>"
                                data-sk-thumb
                                data-src="<?php echo esc_url($image['src']); ?>"
                                data-srcset="<?php echo esc_attr($image['srcset']); ?>"
                                aria-label="Photo <?php echo esc_attr((string) ($index + 1)); ?>"
                            >
                                <img src="<?php echo esc_url($image['src']); ?>" alt="" loading="lazy" />
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="skf-details">
            <?php if ($product['category'] !== null) : ?>
                <p class="skf-eyebrow"><a href="<?php echo esc_url($product['category']['url']); ?>"><?php echo esc_html($product['category']['name']); ?></a></p>
            <?php endif; ?>

            <h1 class="skf-product__title"><?php echo esc_html($product['name']); ?></h1>

            <div class="skf-buyline">
                <span class="skf-price" data-sk-price><?php echo esc_html($selectedVariant['price_label'] ?? 'Ask for price'); ?></span>
                <span class="skf-stock<?php echo $product['in_stock'] ? '' : ' is-out'; ?>" data-sk-stock>
                    <?php echo esc_html($selectedVariant['stock_label'] ?? 'Out of stock'); ?>
                </span>
            </div>

            <?php if ($product['short_description'] !== '') : ?>
                <p class="skf-product__summary"><?php echo esc_html($product['short_description']); ?></p>
            <?php endif; ?>

            <?php foreach ($product['options'] as $option) : ?>
                <?php
                $selectedValue = $selection[$option['slug']] ?? '';
                $label = '';
                foreach ($option['values'] as $value) {
                    if ($value['slug'] === $selectedValue) {
                        $label = $value['value'];
                    }
                }
                ?>
                <div class="skf-option" data-sk-option="<?php echo esc_attr($option['slug']); ?>">
                    <div class="skf-option__head">
                        <span class="skf-option__name"><?php echo esc_html($option['name']); ?></span>
                        <span class="skf-option__value" data-sk-option-label><?php echo esc_html($label); ?></span>
                    </div>

                    <div class="skf-option__values<?php echo $option['display'] === 'color' ? ' is-color' : ''; ?>">
                        <?php foreach ($option['values'] as $value) : ?>
                            <?php $isSelected = $value['slug'] === $selectedValue; ?>
                            <a
                                class="skf-swatch<?php echo $isSelected ? ' is-selected' : ''; ?><?php echo $value['color_hex'] === null ? ' is-label' : ''; ?>"
                                href="<?php echo esc_url($selectionUrl($option['slug'], $value['slug'])); ?>"
                                data-sk-swatch
                                data-option="<?php echo esc_attr($option['slug']); ?>"
                                data-value="<?php echo esc_attr($value['slug']); ?>"
                                data-value-id="<?php echo esc_attr((string) $value['id']); ?>"
                                aria-pressed="<?php echo $isSelected ? 'true' : 'false'; ?>"
                                <?php if ($value['color_hex'] !== null) : ?>style="--skf-swatch: <?php echo esc_attr($value['color_hex']); ?>"<?php endif; ?>
                            >
                                <?php if ($value['color_hex'] !== null) : ?>
                                    <span class="skf-swatch__chip" aria-hidden="true"></span>
                                <?php endif; ?>
                                <span class="skf-swatch__name"><?php echo esc_html($value['value']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($selectedVariant !== null && $selectedVariant['in_stock']) : ?>
                <form class="skf-add" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="storekeeper_cart">
                    <input type="hidden" name="cart_action" value="add">
                    <input type="hidden" name="variant_id" value="<?php echo esc_attr((string) $selectedVariant['id']); ?>">
                    <?php wp_nonce_field('storekeeper_cart'); ?>

                    <label class="skf-add__qty" for="skf-add-qty">
                        <span>Qty</span>
                        <input
                            id="skf-add-qty"
                            type="number"
                            name="qty"
                            value="1"
                            min="1"
                            max="<?php echo esc_attr((string) max(1, (int) $selectedVariant['available'])); ?>"
                        >
                    </label>

                    <button type="submit" class="skf-button">Add to cart</button>
                </form>
            <?php elseif ($selectedVariant !== null) : ?>
                <p class="skf-notice">This option is out of stock. Choose another colour or ask us when it is back.</p>
            <?php endif; ?>

            <?php if ($product['description'] !== '') : ?>
                <div class="skf-product__description">
                    <?php echo wp_kses_post(wpautop($product['description'])); ?>
                </div>
            <?php endif; ?>

            <?php if ($selectedVariant !== null) : ?>
                <dl class="skf-meta">
                    <div><dt>SKU</dt><dd><?php echo esc_html($selectedVariant['sku']); ?></dd></div>
                    <?php if ($product['price'] !== null) : ?>
                        <div><dt>Pricing</dt><dd><?php echo esc_html($view->isSignedIn() ? 'Your account price' : 'Retail price'); ?></dd></div>
                    <?php endif; ?>
                </dl>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($related !== []) : ?>
        <section class="skf-related">
            <div class="skf-section-head">
                <h2 class="skf-section-title">More in <?php echo esc_html((string) ($product['category']['name'] ?? 'this collection')); ?></h2>
                <a class="skf-section-link" href="<?php echo esc_url((string) ($product['category']['url'] ?? $view->shopUrl())); ?>">See all</a>
            </div>
            <div class="skf-grid">
                <?php foreach ($related as $card) : ?>
                    <?php require __DIR__ . '/parts/card.php'; ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>

<script type="application/json" id="skf-product-data">
    <?php
    echo wp_json_encode([
        'variants' => $variantPayload,
        'photos' => $photosBySlug,
        'generalPhotos' => array_map(static fn (array $image): array => [
            'src' => $image['src'],
            'srcset' => $image['srcset'],
            'alt' => $image['alt'],
        ], $product['general_photos']),
        'selection' => $selection,
        'labels' => [
            'inStock' => 'In stock',
            'outOfStock' => 'Out of stock',
            'ask' => 'Ask for price',
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ?>
</script>

<?php
get_footer();
