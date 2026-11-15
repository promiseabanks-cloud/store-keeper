<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Payments\Gateway;

use StoreKeeper\Commerce\Core\Exception\GatewayError;

/**
 * OPay cashier checkout — the Owner's third gateway (Q-001, D-006).
 *
 * Hosted checkout by public key, payment status by HMAC-SHA512 signature.
 * A webhook only ever triggers a status query; the amount that counts is the
 * one OPay's status API returns, never the body of the notification.
 *
 * Amounts travel in kobo because OPay's NGN "total" is in the minor unit.
 */
final class OpayGateway extends AbstractGateway
{
    private const LIVE = 'https://liveapi.opaycheckout.com';

    private const TEST = 'https://testapi.opaycheckout.com';

    protected function features(): array
    {
        return ['checkout', 'payment_link'];
    }

    protected function requiresPublicKey(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return parent::isConfigured() && $this->config->merchantId !== '';
    }

    public function initialize(array $intent): array
    {
        $reference = (string) $intent['reference'];
        $decoded = $this->send('POST', $this->base() . '/api/v1/international/cashier/create', [
            'Authorization' => 'Bearer ' . $this->config->publicKey,
            'MerchantId' => $this->config->merchantId,
        ], [
            'reference' => $reference,
            'country' => $this->config->country,
            'amount' => [
                'total' => (int) $intent['amount_kobo'],
                'currency' => (string) ($intent['currency'] ?? 'NGN'),
            ],
            'callbackUrl' => (string) $intent['callback_url'],
            'returnUrl' => (string) $intent['return_url'],
            'cancelUrl' => (string) $intent['cancel_url'],
            'expireAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 1800),
            'userInfo' => [
                'userName' => (string) ($intent['customer']['name'] ?? ''),
                'userEmail' => (string) ($intent['customer']['email'] ?? ''),
                'userMobile' => (string) ($intent['customer']['phone'] ?? ''),
            ],
        ]);

        $this->assertSuccess($decoded);

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        return $this->initialized(
            $reference,
            (string) ($data['cashierUrl'] ?? $data['checkoutUrl'] ?? ''),
            $decoded
        );
    }

    public function verify(string $reference): array
    {
        $body = json_encode([
            'reference' => $reference,
            'country' => $this->config->country,
        ], JSON_THROW_ON_ERROR);

        $decoded = $this->send('POST', $this->base() . '/api/v1/international/cashier/status', [
            'Authorization' => 'Bearer ' . hash_hmac('sha512', $body, $this->config->secretKey),
            'MerchantId' => $this->config->merchantId,
        ], json_decode($body, true));

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $status = strtoupper((string) ($data['status'] ?? ''));
        $amount = $data['amount'] ?? [];

        $this->assertSuccess($decoded);

        return [
            'status' => match ($status) {
                'SUCCESS', 'SUCCESSFUL', 'PAID' => 'succeeded',
                'FAIL', 'FAILED', 'CLOSE', 'CLOSED', 'CANCELLED' => 'failed',
                default => 'pending',
            },
            'reference' => (string) ($data['reference'] ?? $reference),
            'amount_kobo' => $this->koboFrom($amount),
            'currency' => is_array($amount) ? (string) ($amount['currency'] ?? '') : '',
            'payload' => $decoded,
        ];
    }

    /**
     * OPay signs callbacks with the merchant's private key. Their documentation
     * shows both HMAC-SHA512 and HMAC-SHA3-512, with and without the request
     * timestamp, so every documented shape is accepted (D-018). The signature
     * only gates a status query, so a false accept cannot create money.
     */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $given = trim((string) ($headers['signature'] ?? $headers['authorization'] ?? ''));
        $given = preg_replace('/^bearer\s+/i', '', $given) ?? $given;
        $given = strtolower($given);

        if ($given === '') {
            return false;
        }

        $timestamp = (string) ($headers['requesttimestamp'] ?? '');
        $candidates = [
            hash_hmac('sha512', $rawBody, $this->config->secretKey),
            hash_hmac('sha3-512', $rawBody, $this->config->secretKey),
        ];

        if ($timestamp !== '') {
            $candidates[] = hash_hmac('sha512', $timestamp . $rawBody, $this->config->secretKey);
        }

        foreach ($candidates as $expected) {
            if (hash_equals($expected, $given)) {
                return true;
            }
        }

        return false;
    }

    public function webhookReference(array $payload): ?string
    {
        $reference = $payload['reference']
            ?? (is_array($payload['data'] ?? null) ? ($payload['data']['reference'] ?? null) : null)
            ?? (is_array($payload['payload'] ?? null) ? ($payload['payload']['reference'] ?? null) : null);

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * OPay answers 200 to some errors, with `code` "00000" meaning accepted.
     * Never let one of those decode into a myssteriously empty checkout.
     *
     * @param array<string,mixed> $decoded
     */
    private function assertSuccess(array $decoded): void
    {
        $code = (string) ($decoded['code'] ?? '');

        if ($code === '' || $code === '00000') {
            return;
        }

        throw new GatewayError('OPay rejected the request: ' . (string) ($decoded['message'] ?? $code), [
            'gateway' => $this->id(),
            'code' => $code,
        ]);
    }

    private function base(): string
    {
        return $this->config->testMode ? self::TEST : self::LIVE;
    }
}
