<?php
/**
 * One product card. Expects $card from CatalogView::grid().
 *
 * @var array<string,mixed> $card
 */

if (!isset($card) || !is_array($card)) {
    return;
}
?>
<article class="skf-card<?php echo $card['in_stock'] ? '' : ' is-sold-out'; ?>">
    <a class="skf-card__media" href="<?php echo esc_url($card['url']); ?>" tabindex="-1" aria-hidden="true">
        <?php if (is_array($card['image'])) : ?>
            <img
                src="<?php echo esc_url($card['image']['src']); ?>"
                <?php if ($card['image']['srcset'] !== '') : ?>srcset="<?php echo esc_attr($card['image']['srcset']); ?>"<?php endif; ?>
                sizes="(max-width: 640px) 50vw, 300px"
                alt=""
                loading="lazy"
                decoding="async"
            />
        <?php else : ?>
            <span class="skf-card__placeholder"><?php echo esc_html($card['initials']); ?></span>
        <?php endif; ?>
        <?php if (!$card['in_stock']) : ?>
            <span class="skf-card__badge">Sold out</span>
        <?php endif; ?>
    </a>

    <div class="skf-card__body">
        <?php if ($card['category'] !== null) : ?>
            <p class="skf-card__eyebrow"><?php echo esc_html($card['category']['name']); ?></p>
        <?php endif; ?>

        <h3 class="skf-card__name">
            <a href="<?php echo esc_url($card['url']); ?>"><?php echo esc_html($card['name']); ?></a>
        </h3>

        <p class="skf-card__price">
            <?php if ($card['price']['is_range']) : ?><span class="skf-card__from">from</span> <?php endif; ?>
            <?php echo esc_html($card['price']['label']); ?>
        </p>

        <?php if ($card['swatches'] !== []) : ?>
            <ul class="skf-card__swatches" aria-label="Available colours">
                <?php foreach (array_slice($card['swatches'], 0, 5) as $swatch) : ?>
                    <li>
                        <span
                            class="skf-dot"
                            <?php if ($swatch['color_hex'] !== null) : ?>style="background: <?php echo esc_attr($swatch['color_hex']); ?>"<?php else : ?>title="<?php echo esc_attr($swatch['value']); ?>"<?php endif; ?>
                        ></span>
                    </li>
                <?php endforeach; ?>
                <?php if (count($card['swatches']) > 5) : ?>
                    <li class="skf-card__swatches-more">+<?php echo esc_html((string) (count($card['swatches']) - 5)); ?></li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>
    </div>
</article>
