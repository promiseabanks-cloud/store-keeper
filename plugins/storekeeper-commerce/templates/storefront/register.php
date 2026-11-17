<?php
/**
 * Create an account (§11.1).
 *
 * The number is confirmed with a code before the customer row exists, so a
 * mistyped number is never saved. A manual-approval group leaves the shopper
 * pending at retail prices until staff approve them.
 */

use StoreKeeper\Commerce\Core\Plugin;
use StoreKeeper\Commerce\Storefront\AccountActions;
use StoreKeeper\Commerce\Storefront\AuthActions;
use StoreKeeper\Commerce\Storefront\CartSession;
use StoreKeeper\Commerce\Storefront\Otp\LogOtpSender;
use StoreKeeper\Commerce\Storefront\Storefront;
use StoreKeeper\Commerce\Storefront\StorefrontRegistration;

$customer = Storefront::customer();
$pending = $customer === null ? AccountActions::pendingSignup() : null;
$flash = CartSession::takeFlash();
$options = Plugin::container()->get(StorefrontRegistration::class)->options();
$pendingEmail = $pending !== null && isset($pending['data']['email'])
    ? (string) $pending['data']['email']
    : null;
$pendingChannel = $pending !== null && isset($pending['channel']) ? (string) $pending['channel'] : 'log';

$previewCode = $pending === null ? null : LogOtpSender::previewCode((string) $pending['phone']);

get_header();
?>

<div class="skf-wrap skf-auth">
    <nav class="skf-breadcrumb" aria-label="Breadcrumb">
        <a href="<?php echo esc_url(Storefront::view()->shopUrl()); ?>">Shop</a>
        <span aria-hidden="true">/</span>
        <span>Create an account</span>
    </nav>

    <h1 class="skf-page-title">Create an account</h1>

    <?php if ($flash !== null) : ?>
        <p class="skf-notice skf-notice--info" role="status"><?php echo esc_html($flash); ?></p>
    <?php endif; ?>

    <?php if ($customer !== null) : ?>
        <section class="skf-panel">
            <h2 class="skf-panel__title">You already have an account</h2>
            <p class="skf-field__hint">
                You are signed in as <?php echo esc_html((string) $customer['phone_e164']); ?>.
            </p>
            <p class="skf-auth__actions">
                <a class="skf-button" href="<?php echo esc_url(Storefront::accountUrl()); ?>">Go to your account</a>
                <a class="skf-link" href="<?php echo esc_url(Storefront::view()->shopUrl()); ?>">Continue shopping</a>
            </p>
        </section>
    <?php elseif ($pending !== null) : ?>
        <section class="skf-panel">
            <h2 class="skf-panel__title">Enter the code</h2>
            <p class="skf-field__hint">
                <?php if ($pendingChannel === 'email' && $pendingEmail !== null) : ?>
                    We emailed a 6-digit code to <?php echo esc_html(AuthActions::maskEmail($pendingEmail)); ?>.
                <?php else : ?>
                    We could not email a code to you. Ask the shop to check its email settings.
                <?php endif; ?>
                Your account is created once the code is confirmed.
            </p>

            <?php if ($previewCode !== null) : ?>
                <p class="skf-notice skf-notice--info" role="status">
                    Preview only: the code is <strong><?php echo esc_html($previewCode); ?></strong>.
                </p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="storekeeper_register_verify">
                <?php wp_nonce_field('storekeeper_register_verify'); ?>

                <div class="skf-field">
                    <label for="skf-code">6-digit code</label>
                    <input
                        id="skf-code"
                        type="text"
                        name="code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        required
                        autofocus
                    >
                </div>

                <button class="skf-button" type="submit">Confirm and create my account</button>
            </form>

            <p class="skf-field__hint">
                Wrong number? <a href="<?php echo esc_url(Storefront::registerUrl()); ?>">Start again</a>.
            </p>
        </section>
    <?php else : ?>
        <section class="skf-panel">
            <h2 class="skf-panel__title">Your details</h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="storekeeper_register_request">
                <?php wp_nonce_field('storekeeper_register_request'); ?>

                <div class="skf-field">
                    <label for="skf-name">Your name</label>
                    <input id="skf-name" type="text" name="name" autocomplete="name" required autofocus>
                </div>

                <div class="skf-field">
                    <label for="skf-phone">Phone number</label>
                    <input
                        id="skf-phone"
                        type="tel"
                        name="phone"
                        autocomplete="tel"
                        placeholder="0803 123 4567"
                        required
                    >
                    <p class="skf-field__hint">Your sign-in code goes to your email; we keep this number for delivery.</p>
                </div>

                <fieldset class="skf-field skf-field--choice">
                    <legend>How are you buying?</legend>

                    <?php foreach ($options as $index => $option) : ?>
                        <label class="skf-choice">
                            <input
                                type="radio"
                                name="group_id"
                                value="<?php echo esc_attr((string) $option['id']); ?>"
                                <?php checked($option['type'], 'retail'); ?>
                                required
                            >
                            <span class="skf-choice__body">
                                <span class="skf-choice__label"><?php echo esc_html($option['name']); ?></span>
                                <span class="skf-choice__meta">
                                    <?php echo $option['type'] === 'wholesale'
                                        ? 'Trade prices. We ask for a business name.'
                                        : 'Standard shop prices.'; ?>
                                    <?php if ($option['manual']) : ?>
                                        Approved by the shop before trade prices apply.
                                    <?php endif; ?>
                                </span>
                            </span>
                        </label>
                    <?php endforeach; ?>

                    <?php if ($options === []) : ?>
                        <p class="skf-field__hint">This shop is not taking new accounts right now.</p>
                    <?php endif; ?>
                </fieldset>

                <div class="skf-field">
                    <label for="skf-business">Business name (wholesale only)</label>
                    <input id="skf-business" type="text" name="business_name" autocomplete="organization">
                </div>

                <div class="skf-field">
                    <label for="skf-email">Email address</label>
                    <input id="skf-email" type="email" name="email" autocomplete="email" placeholder="you@example.com" required>
                    <p class="skf-field__hint">We email your 6-digit code here, and your order confirmations.</p>
                </div>

                <button class="skf-button" type="submit" <?php disabled($options === []); ?>>Send me a code</button>
            </form>

            <p class="skf-field__hint">
                Already registered? <a href="<?php echo esc_url(home_url('/sign-in/')); ?>">Sign in</a>.
            </p>
        </section>
    <?php endif; ?>
</div>

<?php
get_footer();
