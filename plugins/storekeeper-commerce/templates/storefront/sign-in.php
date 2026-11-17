<?php
/**
 * Sign in with a phone number and a one-time code (§22.5, §7.4).
 *
 * Two server-rendered steps and no JavaScript: ask for a code, then type it.
 * The pending number lives in a 10-minute HTTP-only cookie, never in the URL.
 */

use StoreKeeper\Commerce\Core\Plugin;
use StoreKeeper\Commerce\Storefront\AuthActions;
use StoreKeeper\Commerce\Storefront\CartSession;
use StoreKeeper\Commerce\Storefront\Storefront;
use StoreKeeper\Commerce\Storefront\Otp\LogOtpSender;
use StoreKeeper\Commerce\Storefront\Otp\OtpSender;

$customer = Storefront::customer();
$pendingPhone = $customer === null ? AuthActions::challengedPhone() : null;
$flash = CartSession::takeFlash();

$previewCode = null;
if ($pendingPhone !== null) {
    $sender = Plugin::container()->get(OtpSender::class);
    $previewCode = $sender instanceof LogOtpSender ? $sender->previewCode($pendingPhone) : null;
}

get_header();
?>

<div class="skf-wrap skf-auth">
    <nav class="skf-breadcrumb" aria-label="Breadcrumb">
        <a href="<?php echo esc_url(Storefront::view()->shopUrl()); ?>">Shop</a>
        <span aria-hidden="true">/</span>
        <span>Sign in</span>
    </nav>

    <h1 class="skf-page-title"><?php echo $customer === null ? 'Sign in' : 'Your account'; ?></h1>

    <?php if ($flash !== null) : ?>
        <p class="skf-notice skf-notice--info" role="status"><?php echo esc_html($flash); ?></p>
    <?php endif; ?>

    <?php if ($customer !== null) : ?>
        <section class="skf-panel">
            <h2 class="skf-panel__title">Hello, <?php echo esc_html((string) $customer['name']); ?></h2>
            <p class="skf-field__hint">
                You are signed in as <?php echo esc_html((string) $customer['phone_e164']); ?>.
                Your prices are applied everywhere in the shop.
            </p>

            <p class="skf-auth__actions">
                <a class="skf-button" href="<?php echo esc_url(Storefront::cartUrl()); ?>">Go to your cart</a>
                <a class="skf-link" href="<?php echo esc_url(Storefront::view()->shopUrl()); ?>">Continue shopping</a>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="storekeeper_sign_out">
                <?php wp_nonce_field('storekeeper_sign_out'); ?>
                <button class="skf-link" type="submit">Sign out</button>
            </form>
        </section>
    <?php elseif ($pendingPhone !== null) : ?>
        <section class="skf-panel">
            <h2 class="skf-panel__title">Enter the code</h2>
            <p class="skf-field__hint">
                We sent a 6-digit code to <?php echo esc_html(AuthActions::mask($pendingPhone)); ?>.
                It expires in 10 minutes.
            </p>

            <?php if ($previewCode !== null) : ?>
                <p class="skf-notice skf-notice--info" role="status">
                    Preview only: the code is <strong><?php echo esc_html($previewCode); ?></strong>.
                </p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="storekeeper_otp_verify">
                <?php wp_nonce_field('storekeeper_otp_verify'); ?>

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

                <button class="skf-button" type="submit">Sign in</button>
            </form>

            <form class="skf-auth__resend" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="storekeeper_otp_request">
                <input type="hidden" name="phone" value="<?php echo esc_attr($pendingPhone); ?>">
                <?php wp_nonce_field('storekeeper_otp_request'); ?>
                <button class="skf-link" type="submit">Send a new code</button>
            </form>

            <p class="skf-field__hint">Wrong number? <a href="<?php echo esc_url(home_url('/sign-in/')); ?>">Start again</a>.</p>
        </section>
    <?php else : ?>
        <section class="skf-panel">
            <h2 class="skf-panel__title">Sign in with your phone</h2>
            <p class="skf-field__hint">
                Wholesale customers sign in to see their own prices. We will text a
                6-digit code to the number the shop has on file.
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="storekeeper_otp_request">
                <?php wp_nonce_field('storekeeper_otp_request'); ?>

                <div class="skf-field">
                    <label for="skf-phone">Phone number</label>
                    <input
                        id="skf-phone"
                        type="tel"
                        name="phone"
                        autocomplete="tel"
                        placeholder="0803 123 4567"
                        required
                        autofocus
                    >
                </div>

                <button class="skf-button" type="submit">Send me a code</button>
            </form>

            <p class="skf-field__hint">
                New customer? Ask the shop to add your number, then sign in here.
                <a href="<?php echo esc_url(Storefront::cartUrl()); ?>">Your cart</a> is kept while you sign in.
            </p>
        </section>
    <?php endif; ?>
</div>

<?php
get_footer();
