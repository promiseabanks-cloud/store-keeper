<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Payments\Gateway;

use StoreKeeper\Commerce\Core\Exception\GatewayError;

/**
 * Shared plumbing for the adapters: JSON requests, error shaping and the
 * amount checks every verification must pass (§13.2).
 */
abstract class AbstractGateway implements GatewayAdapter
{
    public function __construct(
        protected readonly GatewayConfig $config,
        protected readonly HttpClient $http,
    ) {
    }

    public function id(): string
    {
        return $this->config->slug;
    }

    public function label(): string
    {
        return $this->config->label;
    }

    public function isConfigured(): bool
    {
        if ($this->config->secretKey === '') {
            return false;
        }

        return !$this->requiresPublicKey() || $this->config->publicKey !== '';
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    public function feeKobo(int $amountKobo): int
    {
        return 0;
    }

    /** @return list<string> */
    abstract protected function features(): array;

    protected function requiresPublicKey(): bool
    {
        return false;
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    protected function send(string $method, string $url, array $headers, ?array $body = null): array
    {
        $encoded = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);

        if ($encoded !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $response = $this->http->request($method, $url, $headers, $encoded);
        $decoded = json_decode($response['body'], true);

        if ($response['status'] >= 400 || !is_array($decoded)) {
            throw new GatewayError(sprintf('%s rejected the request.', $this->label()), [
                'gateway' => $this->id(),
                'status' => $response['status'],
                'response' => mb_substr($response['body'], 0, 500),
            ]);
        }

        return $decoded;
    }

    /**
     * Normalise a gateway's checkout response into the adapter shape.
     *
     * @param array<string,mixed> $decoded
     * @return array{reference:string,redirect_url:string,payload:array<string,mixed>}
     */
    protected function initialized(string $reference, string $url, array $decoded): array
    {
        if ($url === '') {
            throw new GatewayError(sprintf('%s did not return a checkout URL.', $this->label()), [
                'gateway' => $this->id(),
            ]);
        }

        return ['reference' => $reference, 'redirect_url' => $url, 'payload' => $decoded];
    }

    /** @param array<string,int|string> $amount */
    protected function koboFrom(mixed $amount): int
    {
        if (is_array($amount)) {
            $amount = $amount['total'] ?? $amount['value'] ?? 0;
        }

        return (int) round((float) $amount);
    }
}
