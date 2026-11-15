<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Payments\Gateway;

use StoreKeeper\Commerce\Core\Exception\GatewayError;

/**
 * Paystack (§13.1, §13.2). Card, bank transfer, USSD and bank app through the
 * hosted checkout; verified server-side and again by signed webhook.
 */
final class PaystackGateway extends AbstractGateway
{
    private const BASE = 'https://api.paystack.co';

    protected function features(): array
    {
        return ['checkout', 'payment_link', 'fee_passthrough'];
    }

    /**
     * 1.5% + NGN 100, capped at NGN 2,000, with the NGN 100 waived under
     * NGN 2,500 (Paystack Nigeria, Sept 2026, §13.1).
     */
    public function feeKobo(int $amountKobo): int
    {
        if ($amountKobo <= 0) {
            return 0;
        }

        $percent = (int) round($amountKobo * 0.015);

        if ($amountKobo < 250000) {
            return $percent;
        }

        return min($percent + 10000, 200000);
    }

    public function initialize(array $intent): array
    {
        $reference = (string) $intent['reference'];

        $decoded = $this->send('POST', self::BASE . '/transaction/initialize', [
            'Authorization' => 'Bearer ' . $this->config->secretKey,
        ], [
            'email' => (string) ($intent['customer']['email'] ?? ''),
            'amount' => (int) $intent['amount_kobo'],
            'currency' => (string) ($intent['currency'] ?? 'NGN'),
            'reference' => $reference,
            'callback_url' => (string) $intent['return_url'],
            'metadata' => ['order_number' => (string) $intent['order_number']],
        ]);

        if (($decoded['status'] ?? true) === false) {
            throw new GatewayError('Paystack rejected the request: ' . (string) ($decoded['message'] ?? 'unknown error'), [
                'gateway' => $this->id(),
            ]);
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        return $this->initialized($reference, (string) ($data['authorization_url'] ?? ''), $decoded);
    }

    public function verify(string $reference): array
    {
        $decoded = $this->send('GET', self::BASE . '/transaction/verify/' . rawurlencode($reference), [
            'Authorization' => 'Bearer ' . $this->config->secretKey,
        ]);

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $status = (string) ($data['status'] ?? '');

        return [
            'status' => match ($status) {
                'success' => 'succeeded',
                'failed', 'abandoned', 'reversed' => 'failed',
                default => 'pending',
            },
            'reference' => (string) ($data['reference'] ?? $reference),
            'amount_kobo' => (int) ($data['amount'] ?? 0),
            'currency' => (string) ($data['currency'] ?? ''),
            'payload' => $decoded,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $given = strtolower(trim((string) ($headers['x-paystack-signature'] ?? '')));

        if ($given === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $rawBody, $this->config->secretKey);

        return hash_equals($expected, $given);
    }

    public function webhookReference(array $payload): ?string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reference = $data['reference'] ?? null;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }
}
