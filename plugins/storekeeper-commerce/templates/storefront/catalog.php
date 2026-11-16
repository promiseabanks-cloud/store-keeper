<?php
/**
 * Shop and category pages. Both render the same grid; the category only narrows it.
 *
 * @var \StoreKeeper\Commerce\Storefront\CatalogView $view
 */

$view = \StoreKeeper\Commerce\Storefront\Storefront::view();
$viewName = (string) get_query_var(\StoreKeeper\Commerce\Storefront\Storefront::VIEW);
$category = $viewName === 'category' ? \StoreKeeper\Commerce\Storefront\Storefront::currentCategory() : null;
$search = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
$page = \StoreKeeper\Commerce\Storefront\Storefront::currentPage();
$perPage = 12;

$grid = $view->grid($search, $category === null ? null : (int) $category['id'], $page, $perPage);

$baseUrl = $category === null
    ? $view->shopUrl($search)
    : add_query_arg('q', $search === '' ? false : $search, $view->categoryUrl((string) $category['slug']));

$pageUrl = static fn (int $number): string => $number <= 1
    ? $baseUrl
    : add_query_arg('pg', $number, $baseUrl);

get_header();
?>

<div class="skf-wrap skf-catalog">
    <header class="skf-page-head">
        <p class="skf-eyebrow"><?php echo $category === null ? 'Shop' : 'Collection'; ?></p>
        <h1 class="skf-page-title">
            <?php echo esc_html($category === null ? 'Everything in store' : (string) $category['name']); ?>
        </h1>
        <p class="skf-lede">
            <?php if ($search !== '') : ?>
                <?php echo esc_html(sprintf('%d result%s for “%s”', $grid['total'], $grid['total'] === 1 ? '' : 's', $search)); ?>
                · <a href="<?php echo esc_url($baseUrl); ?>">Clear search</a>
            <?php else : ?>
                <?php echo esc_html(sprintf('%d piece%s in stock, priced for you', $grid['total'], $grid['total'] === 1 ? '' : 's')); ?>
            <?php endif; ?>
        </p>
    </header>

    <?php
    $heading = '';
    require __DIR__ . '/parts/grid.php';
    ?>

    <?php if ($grid['pages'] > 1) : ?>
        <nav class="skf-pager" aria-label="Pages">
            <?php if ($grid['page'] > 1) : ?>
                <a class="skf-pager__step" href="<?php echo esc_url($pageUrl($grid['page'] - 1)); ?>" rel="prev">Previous</a>
            <?php endif; ?>

            <span class="skf-pager__count">Page <?php echo esc_html((string) $grid['page']); ?> of <?php echo esc_html((string) $grid['pages']); ?></span>

            <?php if ($grid['page'] < $grid['pages']) : ?>
                <a class="skf-pager__step" href="<?php echo esc_url($pageUrl($grid['page'] + 1)); ?>" rel="next">Next</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>

<?php
get_footer();
