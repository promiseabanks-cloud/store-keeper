<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Hooks;

use StoreKeeper\Commerce\Core\Brand;

/**
 * Production hooks, translated to WordPress actions with the brand prefix.
 */
final class WpHooks implements Hooks
{
    public function do(string $name, array $payload = []): void
    {
        if (function_exists('do_action')) {
            do_action(Brand::hook($name), $payload);
        }
    }
}
