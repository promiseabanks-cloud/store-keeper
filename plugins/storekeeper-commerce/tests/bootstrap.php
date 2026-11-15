<?php

declare(strict_types=1);

define('SK_ROOT', dirname(__DIR__, 3));
define('SK_COMMERCE_DIR', dirname(__DIR__));

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
