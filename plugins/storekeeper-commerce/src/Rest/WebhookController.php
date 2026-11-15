<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Rest;

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Payments\PaymentService;

/**
 * Public gateway callbacks (§13.2).
 *
 * No capability check: the gateway is the caller. Authentication is the
 * signature, and the answer is always 200 so a rejected callback is not
 * retried forever. The response never reveals whether anything was credited
 * beyond a coarse status, which is enough for a gateway's retry logic and
 * useless to an attacker.
 */
final class WebhookController
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function register(): void
    {
        register_rest_route(Brand::REST_NAMESPACE, '/webhooks/(?P<gateway>[a-z0-9_-]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'receive'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function receive(\WP_REST_Request $request): \WP_REST_Response
    {
        $slug = strtolower((string) $request['gateway']);
        // WordPress canonicalises incoming header names to lowercase with
        // underscores, while gateways name them with hyphens.
        $headers = [];
        foreach ($request->get_headers() as $name => $values) {
            $key = str_replace('_', '-', strtolower((string) $name));
            $headers[$key] = (string) (is_array($values) ? ($values[0] ?? '') : $values);
        }

        $result = $this->payments->handleWebhook($slug, (string) $request->get_body(), $headers);

        return new \WP_REST_Response($result, 200);
    }
}
