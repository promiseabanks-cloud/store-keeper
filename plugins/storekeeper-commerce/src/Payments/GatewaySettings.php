<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Payments;

use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Audit\AuditLogger;
use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Crypto;
use StoreKeeper\Commerce\Payments\Gateway\GatewayConfig;
use StoreKeeper\Commerce\Payments\Gateway\GatewayConfigProvider;

/**
 * Per-store payment gateway configuration (§10.4, §19.4).
 *
 * The customer's money settles into the merchant's own gateway account, so each
 * store holds its own keys. Secret keys are encrypted before they touch the
 * options table and are never returned to the browser.
 */
final class GatewaySettings implements GatewayConfigProvider
{
    public const OPTION = Brand::OPTION_PREFIX . 'payment_gateways';

    /** Slug => label. OPay is the third gateway confirmed by the Owner (Q-001). */
    public const GATEWAYS = [
        'opay' => 'OPay',
        'paystack' => 'Paystack',
        'flutterwave' => 'Flutterwave',
    ];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Masked view for the back office: secrets are reported as present/absent.
     *
     * @return array{gateways:list<array<string,mixed>>,encryption:array{available:bool,message:string}}
     */
    public function toArray(): array
    {
        $stored = $this->stored();
        $gateways = [];

        foreach (self::GATEWAYS as $slug => $label) {
            $row = $stored[$slug] ?? [];
            $secret = (string) ($row['secret_key_enc'] ?? '');

            $gateways[] = [
                'slug' => $slug,
                'label' => $label,
                'enabled' => (bool) ($row['enabled'] ?? false),
                'test_mode' => (bool) ($row['test_mode'] ?? true),
                'public_key' => (string) ($row['public_key'] ?? ''),
                'merchant_id' => (string) ($row['merchant_id'] ?? ''),
                'has_secret_key' => $secret !== '',
                'secret_hint' => $secret !== '' ? '•••• ' . substr((string) Crypto::decrypt($secret), -4) : '',
            ];
        }

        return [
            'gateways' => $gateways,
            'encryption' => [
                'available' => Crypto::available(),
                'message' => Crypto::available()
                    ? ''
                    : 'WSP_ENCRYPTION_KEY is missing or too short, so secret keys cannot be saved yet.',
            ],
        ];
    }

    /**
     * Save gateway settings. A blank secret key keeps the stored one, so the
     * merchant can change a public key without re-typing the secret.
     *
     * @param array<string,mixed> $input
     * @return array{gateways:list<array<string,mixed>>,encryption:array<string,mixed>}
     */
    public function save(array $input, Actor $actor): array
    {
        $stored = $this->stored();
        $changed = [];

        foreach (self::GATEWAYS as $slug => $label) {
            $incoming = $input[$slug] ?? null;
            if (!is_array($incoming)) {
                continue;
            }

            $row = $stored[$slug] ?? [];
            $row['enabled'] = !empty($incoming['enabled']);
            $row['test_mode'] = !empty($incoming['test_mode']);
            $row['public_key'] = trim((string) ($incoming['public_key'] ?? ''));
            $row['merchant_id'] = trim((string) ($incoming['merchant_id'] ?? ''));

            $secret = trim((string) ($incoming['secret_key'] ?? ''));
            if ($secret !== '') {
                $row['secret_key_enc'] = Crypto::encrypt($secret);
            } elseif (!isset($row['secret_key_enc'])) {
                $row['secret_key_enc'] = '';
            }

            $stored[$slug] = $row;
            $changed[] = $slug;
        }

        update_option(self::OPTION, $stored, false);

        $this->audit->log($actor, 'payments.gateways_updated', 'settings', null, null, [
            'gateways' => $changed,
            'enabled' => array_values(array_keys(array_filter(
                $stored,
                static fn (array $row): bool => !empty($row['enabled'])
            ))),
        ]);

        return $this->toArray();
    }

    /**
     * What the checkout may know: enabled gateways and their public keys only.
     *
     * @return list<array{slug:string,label:string,test_mode:bool,public_key:string}>
     */
    public function checkoutGateways(): array
    {
        $out = [];
        foreach ($this->stored() as $slug => $row) {
            if (empty($row['enabled'])) {
                continue;
            }

            $out[] = [
                'slug' => $slug,
                'label' => self::GATEWAYS[$slug] ?? $slug,
                'test_mode' => (bool) ($row['test_mode'] ?? true),
                'public_key' => (string) ($row['public_key'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * One gateway's settings with the secret decrypted, for the adapters.
     * Null when the slug is not a gateway this build knows.
     */
    public function config(string $slug): ?GatewayConfig
    {
        if (!isset(self::GATEWAYS[$slug])) {
            return null;
        }

        $row = $this->stored()[$slug] ?? [];
        $secret = Crypto::decrypt((string) ($row['secret_key_enc'] ?? ''));

        return new GatewayConfig(
            slug: $slug,
            label: self::GATEWAYS[$slug],
            enabled: (bool) ($row['enabled'] ?? false),
            testMode: (bool) ($row['test_mode'] ?? true),
            publicKey: (string) ($row['public_key'] ?? ''),
            secretKey: $secret,
            merchantId: (string) ($row['merchant_id'] ?? ''),
        );
    }

    /** Decrypted secret for a server-side gateway call. Never expose this. */
    public function secretKey(string $slug): string
    {
        $row = $this->stored()[$slug] ?? [];

        return Crypto::decrypt((string) ($row['secret_key_enc'] ?? ''));
    }

    /** @return array<string,array<string,mixed>> */
    private function stored(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }
}
