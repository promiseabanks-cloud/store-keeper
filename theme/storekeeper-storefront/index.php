<?php
/**
 * Fallback template: WordPress pages and any posts the merchant publishes.
 */

get_header();
?>

<div class="skf-wrap skf-page">
    <?php if (have_posts()) : ?>
        <?php if (is_home() && !is_front_page()) : ?>
            <h1 class="skf-page-title"><?php single_post_title(); ?></h1>
        <?php endif; ?>

        <div class="skf-posts">
            <?php while (have_posts()) : the_post(); ?>
                <article <?php post_class(); ?>>
                    <?php if (is_singular()) : ?>
                        <h1 class="skf-page-title"><?php the_title(); ?></h1>
                        <?php the_content(); ?>
                    <?php else : ?>
                        <h2 class="skf-post__title">
                            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        </h2>
                        <p class="skf-lede"><?php echo esc_html(get_the_date()); ?></p>
                        <?php the_excerpt(); ?>
                    <?php endif; ?>
                </article>
            <?php endwhile; ?>
        </div>

        <?php the_posts_pagination(); ?>
    <?php else : ?>
        <h1 class="skf-page-title">Nothing here yet</h1>
        <p class="skf-lede">Try the <a href="<?php echo esc_url(skf_shop_url()); ?>">shop</a>.</p>
    <?php endif; ?>
</div>

<?php
get_footer();
