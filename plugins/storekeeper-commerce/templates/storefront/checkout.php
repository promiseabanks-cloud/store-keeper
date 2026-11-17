<?php
/**
 * Checkout (§22.5). One form, server-validated: the browser chooses shipping and
 * payment, never a price. The order is created by OrderService on submit.
 */

use StoreKeeper\Commerce\Storefront\CartSession;
use StoreKeeper\Commerce\Storefront\Format;
use StoreKeeper\Commerce\Storefront\Storefront;

$view = Storefront::view();
$summary = Storefront::cartSummary();
$options = Storefront::checkoutOptions();
$customer = Storefront::customer();
$flash = CartSession::takeFlash();

$shippingId = (string) ($_POST['shipping'] ?? 'pickup');
$paymentId = (string) ($_POST['payment_method'] ?? 'bank_transfer');

get_header();
?>

<div class="skf-wrap skf-checkout">
    <nav class="skf-breadcrumb" aria-label="Breadcrumb">
        <a href="<?php echo esc_url(home_url('/shop/')); ?>">Shop</a>
        <span aria-hidden="true">/</span>
        <a href="<?php echo esc_url(Storefront::cartUrl()); ?>">Cart</a>
        <span aria-hidden="true">/</span>
        <span>Checkout</span>
    </nav>

    <h1 class="skf-page-title">Checkout</h1>

    <?php if ($flash !== null) : ?>
        <p class="skf-notice skf-notice--error" role="alert"><?php echo esc_html($flash); ?></p>
    <?php endif; ?>

    <?php if ($customer !== null) : ?>
        <p class="skf-notice skf-notice--info" role="status">
            Signed in as <?php echo esc_html((string) $customer['name']); ?> — your group's prices are applied.
        </p>
    <?php else : ?>
        <p class="skf-field__hint">
            A wholesale customer?
            <a href="<?php echo esc_url(home_url('/sign-in/')); ?>">Sign in</a> to check out at your own prices.
        </p>
    <?php endif; ?>

    <?php if ($summary === null || $summary['lines'] === []) : ?>
        <div class="skf-empty">
            <p>There is nothing to check out.</p>
            <a class="skf-button" href="<?php echo esc_url($view->shopUrl()); ?>">Browse the shop</a>
        </div>
    <?php else : ?>
        <form class="skf-checkout__form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="storekeeper_checkout">
            <?php wp_nonce_field('storekeeper_checkout'); ?>

            <div class="skf-checkout__main">
                <section class="skf-panel" aria-labelledby="skf-contact-title">
                    <h2 id="skf-contact-title" class="skf-panel__title">Your details</h2>

                    <div class="skf-field">
                        <label for="skf-name">Full name</label>
                        <input
                            id="skf-name"
                            type="text"
                            name="name"
                            required
                            autocomplete="name"
                            value="<?php echo esc_attr((string) ($_POST['name'] ?? $customer['name'] ?? '')); ?>"
                        >
                    </div>

                    <div class="skf-field">
                        <label for="skf-phone">Phone number</label>
                        <input
                            id="skf-phone"
                            type="tel"
                            name="phone"
                            required
                            autocomplete="tel"
                            placeholder="0803 123 4567"
                            value="<?php echo esc_attr((string) ($_POST['phone'] ?? $customer['phone_e164'] ?? '')); ?>"
                        >
                        <p class="skf-field__hint">We use this to reach you about this order.</p>
                    </div>
                </section>

                <section class="skf-panel" aria-labelledby="skf-shipping-title">
                    <h2 id="skf-shipping-title" class="skf-panel__title">Delivery</h2>

                    <?php foreach ($options['shipping'] as $option) : ?>
                        <label class="skf-choice">
                            <input
                                type="radio"
                                name="shipping"
                                value="<?php echo esc_attr($option['id']); ?>"
                                data-sk-shipping
                                <?php checked($shippingId, $option['id']); ?>
                            >
                            <span class="skf-choice__body">
                                <span class="skf-choice__label"><?php echo esc_html($option['label']); ?></span>
                                <span class="skf-choice__meta">
                                    <?php echo $option['amount_kobo'] === 0 ? 'Free' : esc_html(Format::price($option['amount_kobo'])); ?>
                                    — <?php echo esc_html($option['description']); ?>
                                </span>
                            </span>
                        </label>
                    <?php endforeach; ?>

                    <?php if (count($options['shipping']) > 1) : ?>
                        <div class="skf-address" data-sk-address>
                            <p class="skf-field__hint">Fill these in if you chose delivery.</p>
                            <div class="skf-field">
                                <label for="skf-line1">Street address</label>
                                <input id="skf-line1" type="text" name="line1" autocomplete="address-line1" value="<?php echo esc_attr((string) ($_POST['line1'] ?? '')); ?>">
                            </div>
                            <div class="skf-field">
                                <label for="skf-line2">Apartment, suite (optional)</label>
                                <input id="skf-line2" type="text" name="line2" autocomplete="address-line2" value="<?php echo esc_attr((string) ($_POST['line2'] ?? '')); ?>">
                            </div>
                            <div class="skf-field">
                                <label for="skf-landmark">Landmark (optional)</label>
                                <input id="skf-landmark" type="text" name="landmark" value="<?php echo esc_attr((string) ($_POST['landmark'] ?? '')); ?>">
                            </div>
                            <div class="skf-field">
                                <label for="skf-city">City (optional)</label>
                                <input id="skf-city" type="text" name="city" autocomplete="address-level2" value="<?php echo esc_attr((string) ($_POST['city'] ?? '')); ?>">
                            </div>
                            <div class="skf-field">
                                <label for="skf-lga">Local government</label>
                                <input id="skf-lga" type="text" name="lga" value="<?php echo esc_attr((string) ($_POST['lga'] ?? '')); ?>">
                            </div>
                            <div class="skf-field">
                                <label for="skf-state">State</label>
                                <input id="skf-state" type="text" name="state" value="<?php echo esc_attr((string) ($_POST['state'] ?? '')); ?>">
                            </div>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="skf-panel" aria-labelledby="skf-payment-title">
                    <h2 id="skf-payment-title" class="skf-panel__title">Payment</h2>

                    <?php foreach ($options['payment'] as $option) : ?>
                        <label class="skf-choice">
                            <input
                                type="radio"
                                name="payment_method"
                                value="<?php echo esc_attr($option['id']); ?>"
                                <?php checked($paymentId, $option['id']); ?>
                            >
                            <span class="skf-choice__body">
                                <span class="skf-choice__label"><?php echo esc_html($option['label']); ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>

                    <div class="skf-field">
                        <label for="skf-note">Order note (optional)</label>
                        <textarea id="skf-note" name="note" rows="3"><?php echo esc_textarea((string) ($_POST['note'] ?? '')); ?></textarea>
                    </div>
                </section>
            </div>

            <aside class="skf-cart__summary" aria-label="Order summary">
                <h2 class="skf-cart__summary-title">Your order</h2>

                <ul class="skf-checkout__items">
                    <?php foreach ($summary['lines'] as $line) : ?>
                        <li>
                            <span>
                                <?php echo esc_html($line['product_name']); ?>
                                <?php if ($line['variant_title'] !== '') : ?>
                                    <small><?php echo esc_html($line['variant_title']); ?></small>
                                <?php endif; ?>
                                <small>× <?php echo esc_html((string) $line['qty']); ?></small>
                            </span>
                            <span><?php echo esc_html(Format::price($line['line_total_kobo'])); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <dl class="skf-totals">
                    <div>
                        <dt>Subtotal</dt>
                        <dd><?php echo esc_html(Format::price($summary['subtotal_kobo'])); ?></dd>
                    </div>
                    <?php if (count($options['shipping']) === 1) : ?>
                        <div>
                            <dt>Pickup</dt>
                            <dd>Free</dd>
                        </div>
                    <?php endif; ?>
                </dl>

                <button type="submit" class="skf-button">Place order</button>
                <p class="skf-field__hint">
                    You will see the order number and payment details on the next page.
                </p>
            </aside>
        </form>
    <?php endif; ?>
</div>

<?php
get_footer();
