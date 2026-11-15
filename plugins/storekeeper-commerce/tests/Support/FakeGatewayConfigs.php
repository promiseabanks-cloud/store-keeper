<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Support;

use StoreKeeper\Commerce\Payments\Gateway\GatewayConfig;
use StoreKeeper\Commerce\Payments\Gateway\GatewayConfigProvider;

/** Gateway settings without WordPress options. */
final class FakeGatewayConfigs implements GatewayConfigProvider
{
    /** @var array<string,GatewayConfig> */
    private array $configs = [];

    public function add(GatewayConfig $config): void
    {
        $this->configs[$config->slug] = $config;
    }

    public function config(string $slug): ?GatewayConfig
    {
        return $this->configs[$slug] ?? null;
    }
}
