<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Payments\Gateway;

/**
 * One gateway's configuration, with the secret already decrypted (§13.2, §19.4).
 *
 * Built by GatewaySettings on demand and never serialised to a response.
 */
final class GatewayConfig
{
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly bool $enabled,
        public readonly bool $testMode,
        public readonly string $publicKey = '',
        public readonly string $secretKey = '',
        public readonly string $merchantId = '',
        public readonly string $currency = 'NGN',
        public readonly string $country = 'NG',
    ) {
    }
}
