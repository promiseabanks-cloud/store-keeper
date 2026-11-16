<?php
/**
 * Confirmation page (§22.5). Readable only with the order's public token, so a
 * guessed order number reveals nothing. It states the payment status honestly:
 * a bank transfer is not "paid" until staff confirm it.
 */

use StoreKeeper\Commerce\Storefront\Format;
use StoreKeeper\Commerce\Storefront\Storefront;

$view = Storefront::view();
$order = Storefront::currentOrder();

if ($order === null) {
    return;
}

$items = \StoreKeeper\Commerce\Core\Plugin::container()
    ->get(\StoreKeeper\Commerce\Orders\OrderService::class)
    ->items((int) $order['id']);

$address = $order['shipping_address'] !== null
    ? json_decode((string) $order['shipping_address'], true)
    : null;
$address = is_array($address) ? $address : null;

$bankDetails = (string) get_option('storekeeper_bank_details', '');
$isPaid = in_array((string) $order['payment_status'], ['paid', 'pay_on_delivery'], true);

get_header();
?>

<div class="skf-wrap skf-received">
    <p class="skf-eyebrow">Order <?php echo esc_html((string) $order['number']); ?></p>
    <h1 class="skf-page-title">
        <?php echo $isPaid ? 'Thank you — your order is in.' : 'Order received.'; ?>
    </h1>

    <p class="skf-received__lede">
        <?php if ($isPaid) : ?>
            We have your order and will be in touch about delivery.
        <?php else : ?>
            Your items are held while we wait for payment. The hold is released if payment
            does not arrive in time.
        <?php endif; ?>
    </p>

    <div class="skf-received__layout">
        <section class="skf-panel" aria-labelledby="skf-received-items">
            <h2 id="skf-received-items" class="skf-panel__title">What you ordered</h2>

            <ul class="skf-checkout__items">
                <?php foreach ($items as $item) : ?>
                    <li>
                        <span>
                            <?php echo esc_html((string) $item['product_name']); ?>
                            <small><?php echo esc_html((string) $item['variant_title']); ?></small>
                            <small>× <?php echo esc_html((string) $item['qty']); ?></small>
                        </span>
                        <span><?php echo esc_html(Format::price((int) $item['line_total_kobo'])); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <dl class="skf-totals">
                <div>
                    <dt>Subtotal</dt>
                    <dd><?php echo esc_html(Format::price((int) $order['subtotal_kobo'])); ?></dd>
                </div>
                <?php if ((int) $order['shipping_kobo'] > 0) : ?>
                    <div>
                        <dt>Delivery</dt>
                        <dd><?php echo esc_html(Format::price((int) $order['shipping_kobo'])); ?></dd>
                    </div>
                <?php endif; ?>
                <div class="skf-totals__grand">
                    <dt>Total</dt>
                    <dd><?php echo esc_html(Format::price((int) $order['total_kobo'])); ?></dd>
                </div>
            </dl>
        </section>

        <aside class="skf-panel">
            <h2 class="skf-panel__title">Payment</h2>

            <?php if ($isPaid) : ?>
                <p>Paid in full. Nothing else to do.</p>
            <?php elseif ((string) $order['payment_status'] === 'awaiting_confirmation') : ?>
                <p>We are checking your payment. You will hear from us once it clears.</p>
            <?php else : ?>
                <p>
                    Pay <strong><?php echo esc_html(Format::price((int) $order['total_kobo'])); ?></strong>
                    by bank transfer, using <strong><?php echo esc_html((string) $order['number']); ?></strong>
                    as the reference.
                </p>

                <?php if (trim($bankDetails) !== '') : ?>
                    <div class="skf-bank">
                        <?php echo wp_kses_post(wpautop($bankDetails)); ?>
                    </div>
                <?php else : ?>
                    <p class="skf-field__hint">
                        The store will send the account details to the phone number on this order.
                    </p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($address !== null) : ?>
                <h3 class="skf-panel__subtitle">
                    <?php echo ($address['method'] ?? '') === 'delivery' ? 'Delivering to' : 'Collecting'; ?>
                </h3>
                <p class="skf-address__text">
                    <?php echo esc_html((string) ($address['recipient_name'] ?? '')); ?><br>
                    <?php if (($address['method'] ?? '') === 'delivery') : ?>
                        <?php echo esc_html((string) ($address['line1'] ?? '')); ?><br>
                        <?php if (!empty($address['line2'])) : ?>
                            <?php echo esc_html((string) $address['line2']); ?><br>
                        <?php endif; ?>
                        <?php if (!empty($address['landmark'])) : ?>
                            <?php echo esc_html((string) $address['landmark']); ?><br>
                        <?php endif; ?>
                        <?php echo esc_html(trim((string) ($address['lga'] ?? '') . ', ' . (string) ($address['state'] ?? ''))); ?><br>
                    <?php endif; ?>
                    <?php echo esc_html((string) ($address['phone_e164'] ?? '')); ?>
                </p>
            <?php endif; ?>

            <a class="skf-section-link" href="<?php echo esc_url($view->shopUrl()); ?>">Continue shopping</a>
        </aside>
    </div>
</div>

<?php
get_footer();
