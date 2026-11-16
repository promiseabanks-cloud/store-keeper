<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Payments\Gateway;

use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Payments\GatewaySettings;

/**
 * The configured gateways, one adapter each (§13.2).
 *
 * Adapters are built on demand so a merchant can change keys without a
 * restart and so an unconfigured gateway simply cannot be used.
 */
final class GatewayRegistry
{
    public function __construct(
        private readonly GatewayConfigProvider $configs,
        private readonly HttpClient $http,
    ) {
    }

    /**
     * An adapter for staff-initiated work: the gateway must exist, be enabled
     * and hold usable keys.
     */
    public function get(string $slug): GatewayAdapter
    {
        $config = $this->configs->config($slug);
        $adapter = $config !== null && $config->enabled ? $this->make($config) : null;

        if ($adapter === null || !$adapter->isConfigured()) {
            throw new ValidationFailed('That payment gateway is not available.', [
                'gateway' => 'Enable it and save its keys first.',
            ]);
        }

        return $adapter;
    }

    /**
     * An adapter for callbacks: a gateway that has since been disabled can
     * still be told about a payment that happened while it was on.
     */
    public function optional(string $slug): ?GatewayAdapter
    {
        $config = $this->configs->config($slug);

        if ($config === null) {
            return null;
        }

        $adapter = $this->make($config);

        return $adapter->isConfigured() ? $adapter : null;
    }

    /** @return list<string> */
    public function configuredSlugs(): array
    {
        $slugs = [];

        foreach (array_keys(GatewaySettings::GATEWAYS) as $slug) {
            if ($this->optional($slug) !== null) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    private function make(GatewayConfig $config): GatewayAdapter
    {
        return match ($config->slug) {
            'paystack' => new PaystackGateway($config, $this->http),
            'flutterwave' => new FlutterwaveGateway($config, $this->http),
            'opay' => new OpayGateway($config, $this->http),
            default => throw new ValidationFailed('Unknown payment gateway.', ['gateway' => $config->slug]),
        };
    }
}
