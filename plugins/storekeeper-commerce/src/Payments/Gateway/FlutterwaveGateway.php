<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Payments\Gateway;

use StoreKeeper\Commerce\Core\Exception\GatewayError;

/**
 * Flutterwave Standard checkout (§13.1, §13.2).
 *
 * The webhook is authenticated with Flutterwave's `verif-hash` header. The
 * merchant pastes the same secret hash into the secret-key field, so one
 * stored secret both calls the API and checks the webhook (D-017).
 */
final class FlutterwaveGateway extends AbstractGateway
{
    private const BASE = 'https://api.flutterwave.com/v3';

    protected function features(): array
    {
        return ['checkout', 'payment_link', 'fee_passthrough'];
    }

    /** About 1.4% local, capped at NGN 2,000 (§13.1). */
    public function feeKobo(int $amountKobo): int
    {
        if ($amountKobo <= 0) {
            return 0;
        }

        return min((int) round($amountKobo * 0.014), 200000);
    }

    public function initialize(array $intent): array
    {
        $reference = (string) $intent['reference'];

        $decoded = $this->send('POST', self::BASE . '/payments', [
            'Authorization' => 'Bearer ' . $this->config->secretKey,
        ], [
            'tx_ref' => $reference,
            'amount' => $this->majorUnits((int) $intent['amount_kobo']),
            'currency' => (string) ($intent['currency'] ?? 'NGN'),
            'redirect_url' => (string) $intent['return_url'],
            'customer' => [
                'email' => (string) ($intent['customer']['email'] ?? ''),
                'name' => (string) ($intent['customer']['name'] ?? ''),
                'phonenumber' => (string) ($intent['customer']['phone'] ?? ''),
            ],
            'customizations' => ['title' => 'Order ' . (string) $intent['order_number']],
        ]);

        if (($decoded['status'] ?? 'success') !== 'success') {
            throw new GatewayError('Flutterwave rejected the request: ' . (string) ($decoded['message'] ?? 'unknown error'), [
                'gateway' => $this->id(),
            ]);
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        return $this->initialized($reference, (string) ($data['link'] ?? ''), $decoded);
    }

    public function verify(string $reference): array
    {
        $decoded = $this->send(
            'GET',
            self::BASE . '/transactions/verify_by_reference?tx_ref=' . rawurlencode($reference),
            ['Authorization' => 'Bearer ' . $this->config->secretKey]
        );

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $status = (string) ($data['status'] ?? '');

        return [
            'status' => match ($status) {
                'successful' => 'succeeded',
                'failed', 'cancelled' => 'failed',
                default => 'pending',
            },
            'reference' => (string) ($data['tx_ref'] ?? $reference),
            'amount_kobo' => (int) round(((float) ($data['amount'] ?? 0)) * 100),
            'currency' => (string) ($data['currency'] ?? ''),
            'payload' => $decoded,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $given = trim((string) ($headers['verif-hash'] ?? ''));

        if ($given === '' || $this->config->secretKey === '') {
            return false;
        }

        return hash_equals($this->config->secretKey, $given);
    }

    public function webhookReference(array $payload): ?string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reference = $data['tx_ref'] ?? null;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    private function majorUnits(int $kobo): string
    {
        return number_format($kobo / 100, 2, '.', '');
    }
}
