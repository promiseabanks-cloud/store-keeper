<?php
/**
 * A customer's account (§11.1, §11.2).
 *
 * Their orders, saved addresses, the honesty of a pending approval, and a
 * one-click repeat of any earlier order. Everything here belongs to the signed-in
 * customer; the service refuses an order that is not theirs.
 */

use StoreKeeper\Commerce\Core\Plugin;
use StoreKeeper\Commerce\Storefront\CartSession;
use StoreKeeper\Commerce\Storefront\Format;
use StoreKeeper\Commerce\Storefront\Storefront;
use StoreKeeper\Commerce\Storefront\StorefrontAccount;

$customer = Storefront::customer();

if ($customer === null) {
    return;
}

$account = Plugin::container()->get(StorefrontAccount::class);
$orders = $account->orders((int) $customer['id']);
$addresses = $account->addresses((int) $customer['id']);
$flash = CartSession::takeFlash();
$isPending = (string) $customer['approval_status'] === 'pending';

$requestedGroup = $isPending && !empty($customer['requested_group_id'])
    ? Plugin::container()->get(\StoreKeeper\Commerce\Customers\CustomerRepository::class)
        ->group((int) $customer['requested_group_id'])
    : null;

get_header();
?>

<div class="skf-wrap skf-account">
    <nav class="skf-breadcrumb" aria-label="Breadcrumb">
        <a href="<?php echo esc_url(Storefront::view()->shopUrl()); ?>">Shop</a>
        <span aria-hidden="true">/</span>
        <span>Your account</span>
    </nav>

    <header class="skf-account__head">
        <div>
            <h1 class="skf-page-title">Hello, <?php echo esc_html((string) $customer['name']); ?></h1>
            <p class="skf-field__hint">
                Signed in as <?php echo esc_html((string) $customer['phone_e164']); ?>.
            </p>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="storekeeper_sign_out">
            <?php wp_nonce_field('storekeeper_sign_out'); ?>
            <button class="skf-link" type="submit">Sign out</button>
        </form>
    </header>

    <?php if ($flash !== null) : ?>
        <p class="skf-notice skf-notice--info" role="status"><?php echo esc_html($flash); ?></p>
    <?php endif; ?>

    <?php if ($isPending) : ?>
        <p class="skf-notice skf-notice--info" role="status">
            Your <?php echo esc_html($requestedGroup === null ? 'trade' : (string) $requestedGroup['name']); ?>
            account is waiting for the shop to approve it. Until then you shop at retail prices.
        </p>
    <?php endif; ?>

    <section class="skf-panel" aria-labelledby="skf-account-orders">
        <h2 id="skf-account-orders" class="skf-panel__title">Your orders</h2>

        <?php if ($orders === []) : ?>
            <p class="skf-field__hint">
                No orders yet. <a href="<?php echo esc_url(Storefront::view()->shopUrl()); ?>">Start shopping</a>.
            </p>
        <?php else : ?>
            <ul class="skf-orders">
                <?php foreach ($orders as $order) : ?>
                    <li class="skf-orders__row">
                        <div class="skf-orders__what">
                            <strong><?php echo esc_html((string) $order['number']); ?></strong>
                            <small>
                                <?php echo esc_html(mysql2date(get_option('date_format'), (string) $order['created_at'])); ?>
                                · <?php echo esc_html(StorefrontAccount::statusLabel((string) $order['status'])); ?>
                            </small>
                        </div>

                        <div class="skf-orders__total">
                            <?php echo esc_html(Format::price((int) $order['total_kobo'])); ?>
                        </div>

                        <div class="skf-orders__actions">
                            <a class="skf-link" href="<?php echo esc_url(Storefront::orderUrl($order)); ?>">View</a>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="storekeeper_reorder">
                                <input type="hidden" name="order" value="<?php echo esc_attr((string) $order['number']); ?>">
                                <?php wp_nonce_field('storekeeper_reorder'); ?>
                                <button class="skf-link" type="submit">Order again</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="skf-panel" aria-labelledby="skf-account-addresses">
        <h2 id="skf-account-addresses" class="skf-panel__title">Saved addresses</h2>

        <?php if ($addresses === []) : ?>
            <p class="skf-field__hint">
                No saved addresses. Add one at checkout, or the shop can save it for you.
            </p>
        <?php else : ?>
            <ul class="skf-address-list">
                <?php foreach ($addresses as $address) : ?>
                    <li>
                        <strong>
                            <?php echo esc_html((string) ($address['label'] ?? 'Address')); ?>
                            <?php if ((int) $address['is_default'] === 1) : ?>
                                <small>(default)</small>
                            <?php endif; ?>
                        </strong>
                        <span class="skf-address__text">
                            <?php echo esc_html((string) $address['recipient_name']); ?><br>
                            <?php echo esc_html((string) $address['line1']); ?><br>
                            <?php if (!empty($address['line2'])) : ?>
                                <?php echo esc_html((string) $address['line2']); ?><br>
                            <?php endif; ?>
                            <?php echo esc_html(trim((string) $address['lga'] . ', ' . (string) $address['state'], ', ')); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <p class="skf-account__foot">
        <a class="skf-button" href="<?php echo esc_url(Storefront::cartUrl()); ?>">Go to your cart</a>
        <a class="skf-link" href="<?php echo esc_url(Storefront::view()->shopUrl()); ?>">Continue shopping</a>
    </p>
</div>

<?php
get_footer();
