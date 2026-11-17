<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront;

use StoreKeeper\Commerce\Cart\CartService;
use StoreKeeper\Commerce\Checkout\CheckoutService;
use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;
use StoreKeeper\Commerce\Core\Plugin;

/**
 * Server-rendered cart and checkout forms (D-028).
 *
 * The storefront is a server-rendered theme with no build step, so cart and
 * checkout POST to `admin-post.php`, verify a nonce, and then call the same
 * engine services a REST controller would. No price, quantity or customer is
 * trusted from the request: the cart is re-priced and the order re-computed
 * inside OrderService.
 */
final class CartActions
{
    public static function register(): void
    {
        foreach (['admin_post_storekeeper_cart', 'admin_post_nopriv_storekeeper_cart'] as $hook) {
            add_action($hook, [self::class, 'cart']);
        }

        foreach (['admin_post_storekeeper_checkout', 'admin_post_nopriv_storekeeper_checkout'] as $hook) {
            add_action($hook, [self::class, 'checkout']);
        }
    }

    public static function cart(): void
    {
        self::verifyNonce(
            'storekeeper_cart',
            'This page had expired, so the cart was not changed. Please try again.'
        );

        $action = sanitize_key((string) ($_POST['cart_action'] ?? ''));
        $service = Plugin::container()->get(CartService::class);
        $cart = CartSession::cart($action === 'add');
        $customer = Plugin::container()->get(CustomerContext::class)->current();

        try {
            if ($cart === null) {
                throw new \StoreKeeper\Commerce\Core\Exception\ValidationFailed('Your cart is empty.');
            }

            match ($action) {
                'add' => $service->add($cart, [
                    'variant_id' => (int) ($_POST['variant_id'] ?? 0),
                    'qty' => (int) ($_POST['qty'] ?? 1),
                    'pack_unit_id' => (int) ($_POST['pack_unit_id'] ?? 0),
                ], $customer),
                'update' => $service->setQty(
                    $cart,
                    (int) ($_POST['line_id'] ?? 0),
                    (int) ($_POST['qty'] ?? 1),
                    $customer
                ),
                'remove' => $service->remove($cart, (int) ($_POST['line_id'] ?? 0), $customer),
                'clear' => $service->clear($cart),
                default => throw new \StoreKeeper\Commerce\Core\Exception\ValidationFailed('Unknown cart action.'),
            };
        } catch (StoreKeeperException $error) {
            CartSession::flash($error->getMessage());
        }

        self::redirect(home_url('/cart/'));
    }

    public static function checkout(): void
    {
        self::verifyNonce(
            'storekeeper_checkout',
            'This checkout page had expired, so the order was not placed. Your cart is still here.'
        );

        $token = CartSession::token();

        if ($token === null) {
            self::redirect(home_url('/cart/'));
        }

        $customer = Plugin::container()->get(CustomerContext::class)->current();
        $service = Plugin::container()->get(CheckoutService::class);

        try {
            $result = $service->place($_POST, CartSession::hash($token), $customer);
        } catch (StoreKeeperException $error) {
            CartSession::flash($error->getMessage());
            self::redirect(home_url('/checkout/'));
        }

        $payment = $result['payment'];

        if (is_array($payment) && ($payment['redirect_url'] ?? '') !== '') {
            wp_redirect(esc_url_raw((string) $payment['redirect_url']));
            exit;
        }

        self::redirect(Storefront::orderUrl($result['order']));
    }

    private static function verifyNonce(string $action, string $expiredMessage): void
    {
        FormNonce::verify(
            $action,
            $expiredMessage,
            $action === 'storekeeper_checkout' ? home_url('/checkout/') : home_url('/cart/')
        );
    }

    private static function redirect(string $url): void
    {
        wp_safe_redirect($url);
        exit;
    }
}
