<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Rest;

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Capabilities;
use StoreKeeper\Commerce\Core\Exception\NotFound;
use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;
use StoreKeeper\Commerce\Orders\OrderService;
use StoreKeeper\Commerce\Orders\ShipmentService;

/**
 * Parcels (§14, §18): create one from an order, move one along its lifecycle,
 * and move several at once from the "to pack" list.
 */
final class ShipmentController
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly OrderService $orders,
        private readonly Api $api,
    ) {
    }

    public function register(): void
    {
        $namespace = Brand::REST_NAMESPACE;

        register_rest_route($namespace, '/shipments', [
            'methods' => 'GET',
            'callback' => [$this, 'listing'],
            'permission_callback' => Api::requireCapability(Capabilities::CREATE_ORDERS),
        ]);

        register_rest_route($namespace, '/orders/(?P<uuid>[0-9a-fA-F-]{36})/shipments', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'index'],
                'permission_callback' => Api::requireCapability(Capabilities::CREATE_ORDERS),
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create'],
                'permission_callback' => Api::requireCapability(Capabilities::UPDATE_SHIPMENTS),
            ],
        ]);

        register_rest_route($namespace, '/shipments/(?P<uuid>[0-9a-fA-F-]{36})', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'show'],
                'permission_callback' => Api::requireCapability(Capabilities::CREATE_ORDERS),
            ],
            [
                'methods' => 'PATCH',
                'callback' => [$this, 'update'],
                'permission_callback' => Api::requireCapability(Capabilities::UPDATE_SHIPMENTS),
            ],
        ]);

        register_rest_route($namespace, '/shipments/bulk-status', [
            'methods' => 'POST',
            'callback' => [$this, 'bulkStatus'],
            'permission_callback' => Api::requireCapability(Capabilities::UPDATE_SHIPMENTS),
        ]);
    }

    /** Parcels across orders: the "to pack" list and the dispatch board. */
    public function listing(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $status = trim((string) ($request->get_param('status') ?? ''));

            return new \WP_REST_Response([
                'data' => $this->shipments->listing(
                    $status === '' ? null : $status,
                    (int) ($request->get_param('limit') ?: 100)
                ),
            ], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $order = $this->orders->findByUuid((string) $request['uuid']);

            if ($order === null) {
                throw new NotFound('order', (string) $request['uuid']);
            }

            return new \WP_REST_Response([
                'data' => $this->shipments->forOrder((int) $order['id']),
            ], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $shipment = $this->shipments->findByUuid((string) $request['uuid']);

            if ($shipment === null) {
                throw new NotFound('shipment', (string) $request['uuid']);
            }

            return new \WP_REST_Response(['data' => $shipment], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $order = $this->orders->findByUuid((string) $request['uuid']);

            if ($order === null) {
                throw new NotFound('order', (string) $request['uuid']);
            }

            $body = (array) $request->get_json_params();
            $lines = is_array($body['lines'] ?? null) ? $body['lines'] : [];

            $shipment = $this->shipments->create(
                (int) $order['id'],
                $lines,
                Api::staffActor($request),
                array_diff_key($body, ['lines' => true])
            );

            return new \WP_REST_Response(['data' => $shipment], 201);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $shipment = $this->shipments->findByUuid((string) $request['uuid']);

            if ($shipment === null) {
                throw new NotFound('shipment', (string) $request['uuid']);
            }

            return new \WP_REST_Response([
                'data' => $this->shipments->update(
                    (int) $shipment['id'],
                    (array) $request->get_json_params(),
                    Api::staffActor($request)
                ),
            ], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function bulkStatus(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $body = (array) $request->get_json_params();
            $ids = is_array($body['shipment_ids'] ?? null) ? $body['shipment_ids'] : [];

            return new \WP_REST_Response([
                'data' => $this->shipments->bulkStatus(
                    array_map('intval', $ids),
                    (string) ($body['status'] ?? ''),
                    Api::staffActor($request)
                ),
            ], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }
}
