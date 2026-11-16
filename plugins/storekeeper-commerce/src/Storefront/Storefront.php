<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront;

use StoreKeeper\Commerce\Core\Brand;

/**
 * Clean storefront URLs and their templates (§22.5).
 *
 * The shop is not a WordPress post type: products live in the engine's own tables,
 * so `/shop/`, `/product/{slug}/` and `/product-category/{slug}/` are rewrite rules
 * that render plugin templates inside the active theme.
 */
final class Storefront
{
    public const VIEW = 'sk_view';

    public const PRODUCT = 'sk_product';

    public const CATEGORY = 'sk_category';

    public const PAGE = 'sk_page';

    public const RECEIVED = 'sk_received';

    public const ORDER = 'sk_order';

    public static function register(CatalogView $view): void
    {
        add_action('init', static function (): void {
            self::rewrites();
        });

        add_filter('query_vars', static function (array $vars): array {
            return array_merge($vars, [self::VIEW, self::PRODUCT, self::CATEGORY, self::PAGE, self::ORDER]);
        });

        CartActions::register();

        add_filter('template_include', static function (string $template): string {
            return self::template((string) get_query_var(self::VIEW), $template);
        }, 99);

        // The shop is not the blog home: stop WordPress canonicalising /shop/ to /.
        add_filter('redirect_canonical', static function ($redirect, string $requested) {
            return get_query_var(self::VIEW) !== '' ? false : $redirect;
        }, 10, 2);

        add_action('wp_enqueue_scripts', static function (): void {
            self::assets();
        });

        add_filter('document_title_parts', static function (array $parts): array {
            return self::title($parts);
        });

        add_action('wp_head', static function () use ($view): void {
            self::meta($view);
        }, 5);

        // Anything that changes what the shop shows invalidates guest fragments.
        foreach ([
            'catalog/product_created',
            'stock/received',
            'stock/moved',
            'stock/transfer_received',
            'order/created',
            'order/cancelled',
            'order/completed',
        ] as $event) {
            add_action(Brand::hook($event), static function (): void {
                Cache::flush();
            });
        }
    }

    public static function rewrites(): void
    {
        add_rewrite_rule('^shop/?$', 'index.php?' . self::VIEW . '=shop', 'top');
        add_rewrite_rule('^shop/page/([0-9]+)/?$', 'index.php?' . self::VIEW . '=shop&' . self::PAGE . '=$matches[1]', 'top');
        add_rewrite_rule('^product/([^/]+)/?$', 'index.php?' . self::VIEW . '=product&' . self::PRODUCT . '=$matches[1]', 'top');
        add_rewrite_rule('^product-category/([^/]+)/?$', 'index.php?' . self::VIEW . '=category&' . self::CATEGORY . '=$matches[1]', 'top');
        add_rewrite_rule('^cart/?$', 'index.php?' . self::VIEW . '=cart', 'top');
        add_rewrite_rule('^checkout/?$', 'index.php?' . self::VIEW . '=checkout', 'top');
        add_rewrite_rule(
            '^order-received/([A-Za-z0-9-]+)/?$',
            'index.php?' . self::VIEW . '=received&' . self::ORDER . '=$matches[1]',
            'top'
        );
    }

    public static function activate(): void
    {
        self::rewrites();
        flush_rewrite_rules(false);
    }

    private static function template(string $view, string $fallback): string
    {
        $root = dirname(__DIR__, 2) . '/templates/storefront/';

        $file = match ($view) {
            'shop', 'category' => $root . 'catalog.php',
            'product' => $root . 'product.php',
            'cart' => $root . 'cart.php',
            'checkout' => $root . 'checkout.php',
            'received' => $root . 'order-received.php',
            default => null,
        };

        if ($file === null || !is_file($file)) {
            return $fallback;
        }

        if ($view === 'product' && self::currentProduct() === null) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();

            return $root . 'not-found.php';
        }

        if ($view === 'category' && self::currentCategory() === null) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();

            return $root . 'not-found.php';
        }

        // An order page is only readable with the order's public token.
        if ($view === 'received' && self::currentOrder() === null) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();

            return $root . 'not-found.php';
        }

        return $file;
    }

    /** @return array<string,mixed>|null */
    public static function currentProduct(): ?array
    {
        $slug = (string) get_query_var(self::PRODUCT);
        if ($slug === '') {
            return null;
        }

        $selection = [];
        foreach ($_GET as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $selection[sanitize_title($key)] = sanitize_title($value);
            }
        }

        return self::view()->product($slug, $selection);
    }

    /** @return array<string,mixed>|null */
    public static function currentCategory(): ?array
    {
        $slug = (string) get_query_var(self::CATEGORY);

        return $slug === '' ? null : self::view()->categoryBySlug($slug);
    }

    public static function view(): CatalogView
    {
        return \StoreKeeper\Commerce\Core\Plugin::container()->get(CatalogView::class);
    }

    /** @return array<string,mixed>|null the signed-in storefront customer */
    public static function customer(): ?array
    {
        return \StoreKeeper\Commerce\Core\Plugin::container()->get(CustomerContext::class)->current();
    }

    /** @return array<string,mixed>|null */
    public static function cartSummary(): ?array
    {
        return CartSession::summary();
    }

    /** @return array{shipping:list<array<string,mixed>>,payment:list<array<string,mixed>>} */
    public static function checkoutOptions(): array
    {
        $checkout = \StoreKeeper\Commerce\Core\Plugin::container()->get(
            \StoreKeeper\Commerce\Checkout\CheckoutService::class
        );

        return [
            'shipping' => $checkout->shippingOptions(),
            'payment' => $checkout->paymentOptions(),
        ];
    }

    /** @return array<string,mixed>|null the order named in the URL, only with its token */
    public static function currentOrder(): ?array
    {
        $number = (string) get_query_var(self::ORDER);
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash((string) $_GET['token'])) : '';

        if ($number === '' || $token === '') {
            return null;
        }

        return \StoreKeeper\Commerce\Core\Plugin::container()
            ->get(\StoreKeeper\Commerce\Orders\OrderService::class)
            ->findByPublicToken($number, $token);
    }

    /** @param array<string,mixed> $order */
    public static function orderUrl(array $order): string
    {
        return add_query_arg(
            ['token' => (string) $order['public_token']],
            home_url('/order-received/' . rawurlencode((string) $order['number']) . '/')
        );
    }

    public static function cartUrl(): string
    {
        return home_url('/cart/');
    }

    public static function checkoutUrl(): string
    {
        return home_url('/checkout/');
    }

    public static function currentPage(): int
    {
        return max(1, (int) get_query_var(self::PAGE));
    }

    /** @param array<string,string> $parts */
    private static function title(array $parts): array
    {
        $viewName = (string) get_query_var(self::VIEW);

        if ($viewName === 'product') {
            $product = self::currentProduct();
            if ($product !== null) {
                $parts['title'] = $product['seo']['title'];
            }
        } elseif ($viewName === 'category') {
            $category = self::currentCategory();
            if ($category !== null) {
                $parts['title'] = (string) $category['name'];
            }
        } elseif ($viewName === 'shop') {
            $parts['title'] = 'Shop';
        } elseif ($viewName === 'cart') {
            $parts['title'] = 'Your cart';
        } elseif ($viewName === 'checkout') {
            $parts['title'] = 'Checkout';
        } elseif ($viewName === 'received') {
            $order = self::currentOrder();
            $parts['title'] = $order === null ? 'Order' : 'Order ' . $order['number'];
        }

        return $parts;
    }

    private static function meta(CatalogView $view): void
    {
        $viewName = (string) get_query_var(self::VIEW);

        if ($viewName === 'product') {
            $product = self::currentProduct();
            if ($product === null) {
                return;
            }

            $title = $product['seo']['title'] . ' — ' . get_bloginfo('name');
            $description = $product['seo']['description'];
            $url = $product['url'];
            $image = $product['seo']['image'];
        } elseif ($viewName === 'shop' || $viewName === 'category') {
            $category = $viewName === 'category' ? self::currentCategory() : null;
            if ($viewName === 'category' && $category === null) {
                return;
            }
            $title = ($category['name'] ?? 'Shop') . ' — ' . get_bloginfo('name');
            $description = (string) get_bloginfo('description');
            $url = $category !== null ? $view->categoryUrl((string) $category['slug']) : $view->shopUrl();
            $image = null;
        } else {
            return;
        }

        printf('<link rel="canonical" href="%s" />' . "\n", esc_url($url));
        printf('<meta property="og:type" content="%s" />' . "\n", $viewName === 'product' ? 'product' : 'website');
        printf('<meta property="og:title" content="%s" />' . "\n", esc_attr($title));
        printf('<meta property="og:description" content="%s" />' . "\n", esc_attr(wp_strip_all_tags($description)));
        printf('<meta property="og:url" content="%s" />' . "\n", esc_url($url));
        if (is_string($image) && $image !== '') {
            printf('<meta property="og:image" content="%s" />' . "\n", esc_url($image));
        }
        printf('<meta name="twitter:card" content="%s" />' . "\n", $image ? 'summary_large_image' : 'summary');
    }

    private static function assets(): void
    {
        $base = dirname(__DIR__, 2);
        $css = $base . '/assets/storefront/storefront.css';
        $js = $base . '/assets/storefront/product.js';

        wp_enqueue_style(
            'storekeeper-storefront',
            plugins_url('assets/storefront/storefront.css', $base . '/storekeeper-commerce.php'),
            [],
            is_file($css) ? (string) filemtime($css) : '0.1.0'
        );

        if (get_query_var(self::VIEW) === 'product') {
            wp_enqueue_script(
                'storekeeper-product',
                plugins_url('assets/storefront/product.js', $base . '/storekeeper-commerce.php'),
                [],
                is_file($js) ? (string) filemtime($js) : '0.1.0',
                true
            );
        }
    }
}
