<?php

declare(strict_types=1);

namespace StoreKeeper\Platform\Http;

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Platform\Billing\BillingService;

/**
 * The Owner's Paystack webhook (§17.4). The signature is checked with the same
 * HMAC-SHA512 rule as the store gateways (§13.2); without a configured secret
 * the endpoint refuses instead of accepting unverified events.
 */
final class WebhookController
{
    private const NAMESPACE = 'storekeeper-platform/v1';

    public function __construct(private readonly BillingService $billing)
    {
    }

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $secret = (string) get_site_option(Brand::OPTION_PREFIX . 'platform_paystack_secret', '');

        if ($secret === '') {
            return new \WP_Error('billing_not_configured', 'Platform billing is not configured.', ['status' => 503]);
        }

        $body = (string) $request->get_body();
        $signature = (string) $request->get_header('x-paystack-signature');
        $expected = hash_hmac('sha512', $body, $secret);

        if ($signature === '' || !hash_equals($expected, $signature)) {
            return new \WP_Error('invalid_signature', 'Invalid signature.', ['status' => 401]);
        }

        $event = json_decode($body, true);
        if (!is_array($event)) {
            return new \WP_Error('invalid_payload', 'Invalid payload.', ['status' => 400]);
        }

        $result = $this->billing->applyPaystackEvent($event);

        return new \WP_REST_Response(['data' => $result], 200);
    }
}
