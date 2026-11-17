<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Rest;

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Capabilities;
use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;
use StoreKeeper\Commerce\Shipping\ShippingService;

/**
 * Shipping zones and methods (§13.3, §18).
 *
 * Reads are open to anyone who can create an order; only a manager may change
 * where the shop delivers and what it charges.
 */
final class ShippingController
{
    public function __construct(
        private readonly ShippingService $shipping,
        private readonly Api $api,
    ) {
    }

    public function register(): void
    {
        $namespace = Brand::REST_NAMESPACE;

        register_rest_route($namespace, '/shipping/zones', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'zones'],
                'permission_callback' => Api::requireCapability(Capabilities::CREATE_ORDERS),
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'createZone'],
                'permission_callback' => Api::requireCapability(Capabilities::MANAGE_SETTINGS),
            ],
        ]);

        register_rest_route($namespace, '/shipping/methods', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'methods'],
                'permission_callback' => Api::requireCapability(Capabilities::CREATE_ORDERS),
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'createMethod'],
                'permission_callback' => Api::requireCapability(Capabilities::MANAGE_SETTINGS),
            ],
        ]);

        register_rest_route($namespace, '/shipping/methods/(?P<id>\d+)', [
            [
                'methods' => 'PATCH',
                'callback' => [$this, 'updateMethod'],
                'permission_callback' => Api::requireCapability(Capabilities::MANAGE_SETTINGS),
            ],
        ]);
    }

    public function zones(): \WP_REST_Response
    {
        return new \WP_REST_Response(['data' => $this->shipping->zones()], 200);
    }

    public function methods(): \WP_REST_Response
    {
        $rows = array_map(static fn (array $method): array => [
            'id' => (int) $method['id'],
            'zone_id' => $method['zone_id'] === null ? null : (int) $method['zone_id'],
            'name' => (string) $method['name'],
            'type' => (string) $method['type'],
            'type_label' => ShippingService::typeLabel((string) $method['type']),
            'config' => $method['config'],
            'group_ids' => $method['group_ids'],
            'is_active' => (int) $method['is_active'] === 1,
            'sort_order' => (int) $method['sort_order'],
        ], $this->shipping->methods(false));

        return new \WP_REST_Response(['data' => $rows], 200);
    }

    public function createZone(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $zone = $this->shipping->createZone(
                (array) $request->get_json_params(),
                Api::staffActor($request)
            );

            return new \WP_REST_Response(['data' => $zone], 201);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function createMethod(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $method = $this->shipping->createMethod(
                (array) $request->get_json_params(),
                Api::staffActor($request)
            );

            return new \WP_REST_Response(['data' => $method], 201);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function updateMethod(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $method = $this->shipping->updateMethod(
                (int) $request['id'],
                (array) $request->get_json_params(),
                Api::staffActor($request)
            );

            return new \WP_REST_Response(['data' => $method], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }
}
