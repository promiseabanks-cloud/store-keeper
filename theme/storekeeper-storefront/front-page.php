<?php
/**
 * Home: one statement, then new arrivals straight from the engine.
 */

if (class_exists(\StoreKeeper\Commerce\Storefront\Storefront::class)) {
    $skf_shop = \StoreKeeper\Commerce\Storefront\Storefront::view()->shopUrl();
} else {
    $skf_shop = home_url('/');
}

get_header();
?>

<div class="skf-wrap">
    <section class="skf-page-head">
        <p class="skf-eyebrow"><?php bloginfo('name'); ?></p>
        <h1 class="skf-page-title"><?php echo esc_html(get_bloginfo('description') ?: 'Good pieces, honest prices'); ?></h1>
        <p class="skf-lede">
            Live stock, priced for you.
            <a href="<?php echo esc_url($skf_shop); ?>">Browse everything</a>.
        </p>
    </section>

    <section>
        <?php
        echo do_blocks('<!-- wp:storekeeper/product-grid {"heading":"New in","limit":8} /-->');
        ?>
    </section>
</div>

<?php
get_footer();
