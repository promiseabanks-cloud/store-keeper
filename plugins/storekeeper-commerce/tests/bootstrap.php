<?php

declare(strict_types=1);

define('SK_ROOT', dirname(__DIR__, 3));
define('SK_COMMERCE_DIR', dirname(__DIR__));

/*
 * The engine has no WordPress, but the storefront read model is plain PHP that
 * links to pages. These stubs keep those two dependencies honest and tiny so the
 * storefront's queries are tested against real SQL, not a mock.
 */
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://shop.test' . ($path === '' ? '/' : $path);
    }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}

$autoload = SK_ROOT . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    // Allow the engine to be exercised before `composer install` (CI safety).
    spl_autoload_register(static function (string $class): void {
        $prefixes = [
            'StoreKeeper\\Commerce\\Tests\\' => SK_COMMERCE_DIR . '/tests/',
            'StoreKeeper\\Commerce\\' => SK_COMMERCE_DIR . '/src/',
            'StoreKeeper\\Platform\\' => SK_ROOT . '/plugins/storekeeper-platform/src/',
        ];

        foreach ($prefixes as $prefix => $directory) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $path = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;

                return;
            }
        }
    });
}
