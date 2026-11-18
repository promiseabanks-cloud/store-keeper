<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Rest;

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;
use StoreKeeper\Commerce\Pos\DeviceGuard;
use StoreKeeper\Commerce\Pos\SyncService;

/**
 * `GET /sync/pull` and `POST /sync/push` (§15.3, §18).
 *
 * Both require the device token; a cashier's PIN is checked at sign-in and each
 * sale carries the cashier's user id. The shared guard applies the device's
 * per-minute request budget (D-041).
 */
final class SyncController
{
    public function __construct(
        private readonly SyncService $sync,
        private readonly DeviceGuard $guard,
    ) {
    }

    public function register(): void
    {
        $namespace = Brand::REST_NAMESPACE;

        register_rest_route($namespace, '/sync/pull', [
            'methods' => 'GET',
            'callback' => [$this, 'pull'],
            'permission_callback' => [$this, 'devicePermission'],
        ]);

        register_rest_route($namespace, '/sync/push', [
            'methods' => 'POST',
            'callback' => [$this, 'push'],
            'permission_callback' => [$this, 'devicePermission'],
        ]);
    }

    public function devicePermission(\WP_REST_Request $request): bool|\WP_Error
    {
        return $this->guard->permission($request);
    }

    public function pull(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        /** @var array<string,mixed> $device */
        $device = (array) $request->get_param('_device');

        try {
            $cursor = $request->get_param('cursor');

            return new \WP_REST_Response([
                'data' => $this->sync->pull(
                    $device,
                    is_string($cursor) || is_numeric($cursor) ? (string) $cursor : null,
                    [
                        'stale_after_minutes' => (int) get_option(Brand::OPTION_PREFIX . 'device_stale_minutes', 10),
                        'offline_buffer_units' => (int) get_option(Brand::OPTION_PREFIX . 'offline_buffer_units', 0),
                        'store_name' => (string) get_bloginfo('name'),
                    ]
                ),
            ], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function push(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        /** @var array<string,mixed> $device */
        $device = (array) $request->get_param('_device');

        try {
            return new \WP_REST_Response([
                'data' => $this->sync->push($device, (array) $request->get_json_params()),
            ], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }
}
