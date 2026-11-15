<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Payments;

/**
 * The gateways this build speaks to (§13.1, D-006).
 *
 * Separate from GatewaySettings so the registry can enumerate them without
 * reading WordPress options.
 */
final class GatewaySettingsMap
{
    public const LABELS = [
        'opay' => 'OPay',
        'paystack' => 'Paystack',
        'flutterwave' => 'Flutterwave',
    ];
}
