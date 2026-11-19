<?php
/**
 * Create an account with an email and a password, or with Google (§11.1).
 *
 * One step, no code to wait for: the shopper chooses how they are buying, and
 * the group's approval rule decides the rest. A manual-approval wholesale group
 * leaves them pending at retail prices until staff approve them. The phone
 * number is optional here and only used for delivery.
 */

use StoreKeeper\Commerce\Core\Plugin;
use StoreKeeper\Commerce\Storefront\CartSession;
use StoreKeeper\Commerce\Storefront\GoogleAuth;
use StoreKeeper\Commerce\Storefront\Storefront;
use StoreKeeper\Commerce\Storefront\StorefrontRegistration;

$customer = Storefront::customer();
$flash = CartSession::takeFlash();
$options = Plugin::container()->get(StorefrontRegistration::class)->options();
$googleEnabled = Plugin::container()->get(GoogleAuth::class)->isConfigured();

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
                You are signed in<?php echo $customer['email'] ? ' as ' . esc_html((string) $customer['email']) : ''; ?>.
            </p>
            <p class="skf-auth__actions">
                <a class="skf-button" href="<?php echo esc_url(Storefront::accountUrl()); ?>">Go to your account</a>
                <a class="skf-link" href="<?php echo esc_url(Storefront::view()->shopUrl()); ?>">Continue shopping</a>
            </p>
        </section>
    <?php else : ?>
        <section class="skf-panel">
            <h2 class="skf-panel__title">Your details</h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="storekeeper_email_register">
                <?php wp_nonce_field('storekeeper_email_register'); ?>

                <div class="skf-field">
                    <label for="skf-name">Your name</label>
                    <input id="skf-name" type="text" name="name" autocomplete="name" required autofocus>
                </div>

                <div class="skf-field">
                    <label for="skf-email">Email address</label>
                    <input id="skf-email" type="email" name="email" autocomplete="email" placeholder="you@example.com" required>
                    <p class="skf-field__hint">You sign in with this, and your order confirmations go here.</p>
                </div>

                <div class="skf-field">
                    <label for="skf-password">Password</label>
                    <input id="skf-password" type="password" name="password" autocomplete="new-password" minlength="8" required>
                    <p class="skf-field__hint">At least 8 characters.</p>
                </div>

                <fieldset class="skf-field skf-field--choice">
                    <legend>How are you buying?</legend>

                    <?php foreach ($options as $option) : ?>
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
                    <label for="skf-phone">Phone number (optional)</label>
                    <input id="skf-phone" type="tel" name="phone" autocomplete="tel" placeholder="0803 123 4567">
                    <p class="skf-field__hint">We keep this for delivery. You can add it at checkout instead.</p>
                </div>

                <button class="skf-button" type="submit" <?php disabled($options === []); ?>>Create my account</button>
            </form>

            <?php if ($googleEnabled) : ?>
                <p class="skf-auth__divider"><span>or</span></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="storekeeper_google_start">
                    <?php wp_nonce_field('storekeeper_google_start'); ?>
                    <button class="skf-button skf-button--google" type="submit">Continue with Google</button>
                </form>
                <p class="skf-field__hint">Google accounts shop at retail prices. To buy wholesale, use the form above.</p>
            <?php endif; ?>

            <p class="skf-field__hint">
                Already registered? <a href="<?php echo esc_url(home_url('/sign-in/')); ?>">Sign in</a>.
            </p>
        </section>
    <?php endif; ?>
</div>

<?php
get_footer();
