<?php
/**
 * Storefront header: brand, shop nav and search.
 */
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skf-skip" href="#main">Skip to content</a>

<header class="skf-header">
    <div class="skf-wrap skf-header__inner">
        <a class="skf-brand" href="<?php echo esc_url(home_url('/')); ?>">
            <span class="skf-brand__mark" aria-hidden="true"><?php echo esc_html(skf_initials()); ?></span>
            <span class="skf-brand__name"><?php bloginfo('name'); ?></span>
        </a>

        <nav class="skf-nav" aria-label="Shop">
            <a href="<?php echo esc_url(skf_shop_url()); ?>" <?php echo is_front_page() ? '' : 'aria-current="page"'; ?>>Shop</a>
            <?php foreach (array_slice(skf_categories(), 0, 5) as $category) : ?>
                <a href="<?php echo esc_url($category['url']); ?>"><?php echo esc_html($category['name']); ?></a>
            <?php endforeach; ?>
        </nav>

        <a class="skf-cart-link" href="<?php echo esc_url(home_url('/cart/')); ?>">
            Cart
            <?php $skfCartCount = skf_cart_count(); ?>
            <?php if ($skfCartCount > 0) : ?>
                <span class="skf-cart-link__count"><?php echo esc_html((string) $skfCartCount); ?></span>
            <?php endif; ?>
        </a>

        <form class="skf-search" role="search" method="get" action="<?php echo esc_url(skf_shop_url()); ?>">
            <label class="screen-reader-text" for="skf-search-input">Search products</label>
            <input
                id="skf-search-input"
                type="search"
                name="q"
                placeholder="Search the shop"
                value="<?php echo isset($_GET['q']) ? esc_attr(sanitize_text_field(wp_unslash((string) $_GET['q']))) : ''; ?>"
            >
            <button type="submit">Search</button>
        </form>
    </div>
</header>

<main id="main" class="skf-main">
