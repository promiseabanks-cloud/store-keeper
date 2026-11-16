<?php
/**
 * Storefront footer.
 */
?>
</main>

<footer class="skf-footer">
    <div class="skf-wrap skf-footer__inner">
        <span>
            &copy; <?php echo esc_html(wp_date('Y')); ?>
            <?php bloginfo('name'); ?> ·
            <a href="<?php echo esc_url(skf_shop_url()); ?>">Shop</a>
            <?php foreach (array_slice(skf_categories(), 0, 3) as $category) : ?>
                · <a href="<?php echo esc_url($category['url']); ?>"><?php echo esc_html($category['name']); ?></a>
            <?php endforeach; ?>
        </span>
        <span class="skf-footer__powered">Powered by Store Keeper</span>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
