<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Rest;

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Capabilities;
use StoreKeeper\Commerce\Payments\GatewaySettings;

/**
 * Payment gateway settings (§10.4, §11.7): read masked keys, save new ones.
 */
final class SettingsController
{
    public function __construct(private readonly GatewaySettings $gateways)
    {
    }

    public function register(): void
    {
        register_rest_route(Brand::REST_NAMESPACE, '/settings/payments', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'show'],
                'permission_callback' => Api::requireCapability(Capabilities::MANAGE_SETTINGS),
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'update'],
                'permission_callback' => Api::requireCapability(Capabilities::MANAGE_SETTINGS),
            ],
        ]);
    }

    public function show(): \WP_REST_Response
    {
        return new \WP_REST_Response(['data' => $this->gateways->toArray()], 200);
    }

    public function update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        if (!is_array($body['gateways'] ?? null)) {
            return new \WP_Error('validation_failed', 'A gateways object is required.', ['status' => 422]);
        }

        return new \WP_REST_Response(
            ['data' => $this->gateways->save($body['gateways'], Api::staffActor($request))],
            200
        );
    }
}
