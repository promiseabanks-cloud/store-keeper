<?php
/**
 * Cart page (§22.5). Plain forms: every action works without JavaScript, and
 * every figure on this page was resolved by the engine on this request.
 *
 * @var \StoreKeeper\Commerce\Storefront\CatalogView $view
 */

use StoreKeeper\Commerce\Storefront\CartSession;
use StoreKeeper\Commerce\Storefront\Format;
use StoreKeeper\Commerce\Storefront\Storefront;

$view = Storefront::view();
$summary = Storefront::cartSummary();
$flash = CartSession::takeFlash();

get_header();
?>

<div class="skf-wrap skf-cart">
    <nav class="skf-breadcrumb" aria-label="Breadcrumb">
        <a href="<?php echo esc_url(home_url('/shop/')); ?>">Shop</a>
        <span aria-hidden="true">/</span>
        <span>Cart</span>
    </nav>

    <h1 class="skf-page-title">Your cart</h1>

    <?php if ($flash !== null) : ?>
        <p class="skf-notice skf-notice--error" role="alert"><?php echo esc_html($flash); ?></p>
    <?php endif; ?>

    <?php if ($summary === null || $summary['lines'] === []) : ?>
        <div class="skf-empty">
            <p>Your cart is empty.</p>
            <a class="skf-button" href="<?php echo esc_url($view->shopUrl()); ?>">Browse the shop</a>
        </div>
    <?php else : ?>
        <div class="skf-cart__layout">
            <div class="skf-cart__lines">
                <?php foreach ($summary['lines'] as $line) : ?>
                    <article class="skf-cart-line<?php echo $line['error'] !== null ? ' is-unavailable' : ''; ?>">
                        <div class="skf-cart-line__body">
                            <h2 class="skf-cart-line__name">
                                <?php if ($line['slug'] !== null) : ?>
                                    <a href="<?php echo esc_url(home_url('/product/' . rawurlencode($line['slug']) . '/')); ?>">
                                        <?php echo esc_html($line['product_name']); ?>
                                    </a>
                                <?php else : ?>
                                    <?php echo esc_html($line['product_name']); ?>
                                <?php endif; ?>
                            </h2>

                            <?php if ($line['variant_title'] !== '') : ?>
                                <p class="skf-cart-line__variant"><?php echo esc_html($line['variant_title']); ?></p>
                            <?php endif; ?>

                            <?php if ($line['pack_name'] !== null) : ?>
                                <p class="skf-cart-line__variant">Per <?php echo esc_html($line['pack_name']); ?></p>
                            <?php endif; ?>

                            <p class="skf-cart-line__unit">
                                <?php if ($line['unit_price_kobo'] === null) : ?>
                                    Ask the store for a price
                                <?php else : ?>
                                    <?php echo esc_html(Format::price($line['unit_price_kobo'])); ?> each
                                <?php endif; ?>
                            </p>

                            <?php if ($line['error'] !== null) : ?>
                                <p class="skf-cart-line__error"><?php echo esc_html($line['error']); ?></p>
                            <?php endif; ?>
                        </div>

                        <form class="skf-cart-line__qty" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="storekeeper_cart">
                            <input type="hidden" name="cart_action" value="update">
                            <input type="hidden" name="line_id" value="<?php echo esc_attr((string) $line['id']); ?>">
                            <?php wp_nonce_field('storekeeper_cart'); ?>
                            <label class="screen-reader-text" for="skf-qty-<?php echo esc_attr((string) $line['id']); ?>">Quantity</label>
                            <input
                                id="skf-qty-<?php echo esc_attr((string) $line['id']); ?>"
                                type="number"
                                name="qty"
                                min="1"
                                value="<?php echo esc_attr((string) $line['qty']); ?>"
                            >
                            <button type="submit" class="skf-button skf-button--ghost">Update</button>
                        </form>

                        <div class="skf-cart-line__end">
                            <p class="skf-cart-line__total"><?php echo esc_html(Format::price($line['line_total_kobo'])); ?></p>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="storekeeper_cart">
                                <input type="hidden" name="cart_action" value="remove">
                                <input type="hidden" name="line_id" value="<?php echo esc_attr((string) $line['id']); ?>">
                                <?php wp_nonce_field('storekeeper_cart'); ?>
                                <button type="submit" class="skf-link-button">Remove</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <aside class="skf-cart__summary" aria-label="Order summary">
                <h2 class="skf-cart__summary-title">Summary</h2>
                <dl class="skf-totals">
                    <div>
                        <dt>Items (<?php echo esc_html((string) $summary['item_count']); ?>)</dt>
                        <dd><?php echo esc_html(Format::price($summary['subtotal_kobo'])); ?></dd>
                    </div>
                    <div>
                        <dt>Delivery</dt>
                        <dd>Chosen at checkout</dd>
                    </div>
                    <div class="skf-totals__grand">
                        <dt>Subtotal</dt>
                        <dd><?php echo esc_html(Format::price($summary['subtotal_kobo'])); ?></dd>
                    </div>
                </dl>

                <?php if ($summary['has_errors']) : ?>
                    <p class="skf-notice skf-notice--error">
                        Fix the highlighted items before checking out.
                    </p>
                    <span class="skf-button is-disabled" aria-disabled="true">Checkout</span>
                <?php else : ?>
                    <a class="skf-button" href="<?php echo esc_url(Storefront::checkoutUrl()); ?>">Checkout</a>
                <?php endif; ?>

                <a class="skf-section-link" href="<?php echo esc_url($view->shopUrl()); ?>">Continue shopping</a>
            </aside>
        </div>
    <?php endif; ?>
</div>

<?php
get_footer();
