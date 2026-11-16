<?php
/**
 * A page that does not exist.
 */

get_header();
?>

<div class="skf-wrap skf-page skf-404">
    <p class="skf-eyebrow">404</p>
    <h1 class="skf-page-title">This page is not here</h1>
    <p class="skf-lede">The shop is still open.</p>
    <p><a class="skf-button" href="<?php echo esc_url(skf_shop_url()); ?>">Back to the shop</a></p>
</div>

<?php
get_footer();
