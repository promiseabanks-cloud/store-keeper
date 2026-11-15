<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Rest;

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Capabilities;
use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;
use StoreKeeper\Commerce\Inventory\InventoryService;

/**
 * Inventory endpoints (§18): locations, stock levels and receipts.
 */
final class InventoryController
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly Api $api,
    ) {
    }

    public function register(): void
    {
        $namespace = Brand::REST_NAMESPACE;

        register_rest_route($namespace, '/locations', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'locations'],
                'permission_callback' => Api::requireCapability(Capabilities::EDIT_PRODUCTS),
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'createLocation'],
                'permission_callback' => Api::requireCapability(Capabilities::MANAGE_SETTINGS),
            ],
        ]);

        register_rest_route($namespace, '/stock', [
            'methods' => 'GET',
            'callback' => [$this, 'stock'],
            'permission_callback' => Api::requireCapability(Capabilities::EDIT_PRODUCTS),
            'args' => [
                'variant_id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route($namespace, '/stock/receipts', [
            'methods' => 'POST',
            'callback' => [$this, 'receive'],
            'permission_callback' => Api::requireCapability(Capabilities::RECEIVE_STOCK),
        ]);
    }

    public function locations(): \WP_REST_Response
    {
        return new \WP_REST_Response(['data' => $this->inventory->locations()], 200);
    }

    public function createLocation(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new \WP_Error('validation_failed', 'A JSON body is required.', ['status' => 422]);
        }

        try {
            return new \WP_REST_Response(
                ['data' => $this->inventory->createLocation($body, Api::staffActor($request))],
                201
            );
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function stock(\WP_REST_Request $request): \WP_REST_Response
    {
        $variantId = (int) $request->get_param('variant_id');

        return new \WP_REST_Response([
            'data' => [
                'variant_id' => $variantId,
                'available_online' => $this->inventory->availableOnline($variantId),
                'movements' => $this->inventory->movements($variantId, null, 50),
            ],
        ], 200);
    }

    public function receive(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        if (!is_array($body) || empty($body['location_id']) || empty($body['lines'])) {
            return new \WP_Error('validation_failed', 'location_id and lines are required.', ['status' => 422]);
        }

        try {
            $result = $this->api->idempotent(
                $request,
                'inventory.receipts.create',
                $body,
                fn (): array => $this->inventory->receive(
                    (int) $body['location_id'],
                    $body['lines'],
                    Api::staffActor($request),
                    $body['supplier'] ?? null,
                    $body['reference'] ?? null
                ),
                201
            );

            return new \WP_REST_Response($result->response, $result->statusCode);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }
}
