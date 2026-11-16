<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront;

use StoreKeeper\Commerce\Core\Brand;

/**
 * The product grid is a dynamic block so a merchant on any block theme can place
 * "new in" or a category on a page (§22.5). The same grid backs a shortcode for
 * classic themes and the storefront's own front page.
 */
final class Blocks
{
    public static function register(CatalogView $view): void
    {
        add_action('init', static function () use ($view): void {
            if (function_exists('register_block_type')) {
                register_block_type(dirname(__DIR__, 2) . '/blocks/product-grid', [
                    'render_callback' => static fn (array $attributes): string => self::grid($view, $attributes),
                ]);
            }

            add_shortcode('storekeeper_products', static function ($attributes) use ($view): string {
                $attributes = is_array($attributes) ? $attributes : [];

                return self::grid($view, [
                    'heading' => (string) ($attributes['heading'] ?? ''),
                    'limit' => (int) ($attributes['limit'] ?? 8),
                    'category' => (string) ($attributes['category'] ?? ''),
                ]);
            });
        });
    }

    /** @param array<string,mixed> $attributes */
    public static function grid(CatalogView $view, array $attributes = []): string
    {
        $limit = min(24, max(1, (int) ($attributes['limit'] ?? 8)));
        $categorySlug = trim((string) ($attributes['category'] ?? ''));
        $heading = trim((string) ($attributes['heading'] ?? ''));

        $category = $categorySlug !== '' ? $view->categoryBySlug($categorySlug) : null;

        $data = self::loadGrid($view, $category === null ? null : (int) $category['id'], $limit);

        return self::template('parts/grid.php', [
            'grid' => $data,
            'heading' => $heading,
            'view' => $view,
            'category' => $category,
        ]);
    }

    /**
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,search:string}
     */
    private static function loadGrid(CatalogView $view, ?int $categoryId, int $limit): array
    {
        $key = sprintf('grid|%s|%s|%d', $view->pricingSignature(), (string) $categoryId, $limit);

        $build = static fn (): array => $view->grid('', $categoryId, 1, $limit);

        // A signed-in customer's price is theirs alone: never cached.
        return $view->isSignedIn() ? $build() : Cache::remember($key, $build);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function template(string $relative, array $data = []): string
    {
        $file = dirname(__DIR__, 2) . '/templates/storefront/' . $relative;
        if (!is_file($file)) {
            return '';
        }

        ob_start();
        (static function () use ($file, $data): void {
            extract($data, EXTR_SKIP);
            require $file;
        })();

        return (string) ob_get_clean();
    }
}
