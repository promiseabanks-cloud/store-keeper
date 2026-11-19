<?php
/**
 * Sign in with an email and a password, or with Google (§7.4, §11.1).
 *
 * Server-rendered and JavaScript-free like the rest of the shop. Customers are
 * not WordPress users: the session cookie this page's form opens is what makes a
 * signed-in customer's own group prices apply everywhere.
 */

use StoreKeeper\Commerce\Core\Plugin;
use StoreKeeper\Commerce\Storefront\CartSession;
use StoreKeeper\Commerce\Storefront\GoogleAuth;
use StoreKeeper\Commerce\Storefront\Storefront;

$customer = Storefront::customer();
$flash = CartSession::takeFlash();
$googleEnabled = Plugin::container()->get(GoogleAuth::class)->isConfigured();

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
                You are signed in<?php echo $customer['email'] ? ' as ' . esc_html((string) $customer['email']) : ''; ?>.
                Your prices are applied everywhere in the shop.
            </p>

            <p class="skf-auth__actions">
                <a class="skf-button" href="<?php echo esc_url(Storefront::accountUrl()); ?>">Go to your account</a>
                <a class="skf-link" href="<?php echo esc_url(Storefront::view()->shopUrl()); ?>">Continue shopping</a>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="storekeeper_sign_out">
                <?php wp_nonce_field('storekeeper_sign_out'); ?>
                <button class="skf-link" type="submit">Sign out</button>
            </form>
        </section>
    <?php else : ?>
        <section class="skf-panel">
            <h2 class="skf-panel__title">Sign in with your email</h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="storekeeper_email_login">
                <?php wp_nonce_field('storekeeper_email_login'); ?>

                <div class="skf-field">
                    <label for="skf-email">Email address</label>
                    <input id="skf-email" type="email" name="email" autocomplete="email" placeholder="you@example.com" required autofocus>
                </div>

                <div class="skf-field">
                    <label for="skf-password">Password</label>
                    <input id="skf-password" type="password" name="password" autocomplete="current-password" required>
                </div>

                <button class="skf-button" type="submit">Sign in</button>
            </form>

            <?php if ($googleEnabled) : ?>
                <p class="skf-auth__divider"><span>or</span></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="storekeeper_google_start">
                    <?php wp_nonce_field('storekeeper_google_start'); ?>
                    <button class="skf-button skf-button--google" type="submit">Continue with Google</button>
                </form>
            <?php endif; ?>

            <p class="skf-field__hint">
                New customer? <a href="<?php echo esc_url(Storefront::registerUrl()); ?>">Create an account</a>.
                <a href="<?php echo esc_url(Storefront::cartUrl()); ?>">Your cart</a> is kept while you sign in.
            </p>
        </section>
    <?php endif; ?>
</div>

<?php
get_footer();
