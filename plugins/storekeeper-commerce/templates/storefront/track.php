<?php
/**
 * Public tracking page (§14.3).
 *
 * The order number alone is not enough: the link carries the order's public
 * token, so a guessed number reveals nothing. No sign-in is needed, which is
 * what lets the shop paste this link into an email or a WhatsApp message.
 */

use StoreKeeper\Commerce\Core\Plugin;
use StoreKeeper\Commerce\Orders\OrderService;
use StoreKeeper\Commerce\Orders\ShipmentService;
use StoreKeeper\Commerce\Storefront\Format;
use StoreKeeper\Commerce\Storefront\Storefront;
use StoreKeeper\Commerce\Storefront\StorefrontAccount;

$view = Storefront::view();
$order = Storefront::currentOrder();

if ($order === null) {
    return;
}

$orderId = (int) $order['id'];
$items = Plugin::container()->get(OrderService::class)->items($orderId);
$shipments = Plugin::container()->get(ShipmentService::class)->forOrder($orderId);

$address = $order['shipping_address'] !== null
    ? json_decode((string) $order['shipping_address'], true)
    : null;
$address = is_array($address) ? $address : null;

$bankDetails = (string) get_option('storekeeper_bank_details', '');
$paymentStatus = (string) $order['payment_status'];
$isPaid = in_array($paymentStatus, ['paid', 'pay_on_delivery'], true);

get_header();
?>

<div class="skf-wrap skf-received">
    <p class="skf-eyebrow">Order <?php echo esc_html((string) $order['number']); ?></p>
    <h1 class="skf-page-title">Track your order</h1>

    <p class="skf-received__lede">
        <?php echo esc_html(StorefrontAccount::statusLabel((string) $order['status'])); ?>
        · Placed <?php echo esc_html(mysql2date(get_option('date_format'), (string) $order['created_at'])); ?>
    </p>

    <div class="skf-received__layout">
        <div>
            <section class="skf-panel" aria-labelledby="skf-track-items">
                <h2 id="skf-track-items" class="skf-panel__title">What you ordered</h2>

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

            <?php require __DIR__ . '/parts/shipments.php'; ?>
        </div>

        <aside class="skf-panel">
            <h2 class="skf-panel__title">Payment</h2>

            <?php if ($isPaid) : ?>
                <p>Paid in full. Nothing else to do.</p>
            <?php elseif ($paymentStatus === 'awaiting_confirmation') : ?>
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
