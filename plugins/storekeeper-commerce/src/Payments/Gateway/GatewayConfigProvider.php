<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Payments\Gateway;

/**
 * Where gateway configuration comes from. GatewaySettings is the real
 * implementation; tests supply a plain in-memory one.
 */
interface GatewayConfigProvider
{
    public function config(string $slug): ?GatewayConfig;
}
