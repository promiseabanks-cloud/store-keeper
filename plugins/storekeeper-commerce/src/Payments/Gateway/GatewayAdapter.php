<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Payments\Gateway;

/**
 * One class per payment gateway (§13.2, D-006).
 *
 * Amounts are integer kobo throughout, which is the engine's money unit
 * (D-016 records the deviation from the brief's Money value object).
 *
 * @phpstan-type Intent array{
 *     reference:string, amount_kobo:int, currency:string, order_number:string,
 *     return_url:string, cancel_url:string, callback_url:string,
 *     customer:array{name:string,email:string,phone:string}
 * }
 * @phpstan-type Verification array{
 *     status:string, reference:string, amount_kobo:int, currency:string, payload:array<string,mixed>
 * }
 */
interface GatewayAdapter
{
    public function id(): string;

    public function label(): string;

    public function isConfigured(): bool;

    /** @param string $feature 'checkout','payment_link','virtual_account','refund','fee_passthrough' */
    public function supports(string $feature): bool;

    /** Fee the gateway will keep, for fee pass-through (§13.1). */
    public function feeKobo(int $amountKobo): int;

    /**
     * Create a hosted checkout and return where to send the customer.
     *
     * @param array<string,mixed> $intent
     * @return array{reference:string,redirect_url:string,payload:array<string,mixed>}
     */
    public function initialize(array $intent): array;

    /**
     * Ask the gateway, server-side, what really happened to a reference.
     *
     * @return array{status:string,reference:string,amount_kobo:int,currency:string,payload:array<string,mixed>}
     */
    public function verify(string $reference): array;

    /**
     * Whether a webhook body really came from this gateway. MUST be checked
     * before anything in the body is believed (§13.2).
     *
     * @param array<string,string> $headers lower-cased header name => value
     */
    public function verifyWebhook(string $rawBody, array $headers): bool;

    /**
     * The gateway reference carried by a verified webhook body.
     *
     * @param array<string,mixed> $payload
     */
    public function webhookReference(array $payload): ?string;
}
