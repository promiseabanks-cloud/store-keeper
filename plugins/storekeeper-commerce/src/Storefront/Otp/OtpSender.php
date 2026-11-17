<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront\Otp;

/**
 * How a one-time code reaches the shopper's phone.
 *
 * The concrete provider is still an owner decision (Q-005 WhatsApp route,
 * Q-006 SMS provider), so this interface is the seam: an adapter sends the same
 * `send()` call through Meta's Cloud API, a BSP or an SMS provider. Until one
 * is configured, `LogOtpSender` is the only implementation.
 */
interface OtpSender
{
    public function send(string $phoneE164, string $code, string $purpose): void;
}
