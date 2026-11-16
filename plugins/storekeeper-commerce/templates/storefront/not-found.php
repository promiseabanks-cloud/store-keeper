<?php
/**
 * A missing product or category, rendered in the shop's own chrome.
 */

get_header();
?>

<div class="skf-wrap skf-404">
    <p class="skf-eyebrow">Not found</p>
    <h1 class="skf-page-title">This piece is no longer here</h1>
    <p class="skf-lede">It may have sold out or moved. The rest of the shop is a click away.</p>
    <p><a class="skf-button" href="<?php echo esc_url(home_url('/shop/')); ?>">Back to the shop</a></p>
</div>

<?php
get_footer();
