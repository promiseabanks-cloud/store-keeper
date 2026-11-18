<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Rest;

use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Capabilities;
use StoreKeeper\Commerce\Core\Exception\NotFound;
use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;
use StoreKeeper\Commerce\Pos\CounterService;
use StoreKeeper\Commerce\Pos\DeviceService;

/**
 * POS devices, cashier PINs and counter operations (§15.1, §18).
 *
 * Staff routes use the normal WP cookie + capability. Device routes require the
 * `Device` token header issued at registration, so an offline-capable app never
 * holds a WordPress login.
 */
final class PosController
{
    public function __construct(
        private readonly DeviceService $devices,
        private readonly CounterService $counter,
        private readonly Api $api,
    ) {
    }

    public function register(): void
    {
        $namespace = Brand::REST_NAMESPACE;

        register_rest_route($namespace, '/pos/devices', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'devices'],
                'permission_callback' => Api::requireCapability(Capabilities::REGISTER_DEVICES),
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'registerDevice'],
                'permission_callback' => Api::requireCapability(Capabilities::REGISTER_DEVICES),
            ],
        ]);

        register_rest_route($namespace, '/pos/devices/(?P<uuid>[0-9a-fA-F-]{36})/revoke', [
            'methods' => 'POST',
            'callback' => [$this, 'revoke'],
            'permission_callback' => Api::requireCapability(Capabilities::REGISTER_DEVICES),
        ]);

        // A signed-in cashier needs the names to pick their own; the list is
        // only names, and it is still behind a valid device token.
        register_rest_route($namespace, '/pos/staff', [
            'methods' => 'GET',
            'callback' => [$this, 'staff'],
            'permission_callback' => [$this, 'devicePermission'],
        ]);

        register_rest_route($namespace, '/pos/pins', [
            'methods' => 'POST',
            'callback' => [$this, 'setPin'],
            'permission_callback' => Api::requireCapability(Capabilities::MANAGE_STAFF),
        ]);

        register_rest_route($namespace, '/pos/cashier/verify', [
            'methods' => 'POST',
            'callback' => [$this, 'verifyPin'],
            'permission_callback' => [$this, 'devicePermission'],
        ]);

        register_rest_route($namespace, '/pos/counter-sales', [
            'methods' => 'POST',
            'callback' => [$this, 'sale'],
            'permission_callback' => [$this, 'devicePermission'],
        ]);

        register_rest_route($namespace, '/pos/counter-returns', [
            'methods' => 'POST',
            'callback' => [$this, 'counterReturn'],
            'permission_callback' => [$this, 'devicePermission'],
        ]);

        register_rest_route($namespace, '/pos/cash-ups', [
            'methods' => 'POST',
            'callback' => [$this, 'cashUp'],
            'permission_callback' => [$this, 'devicePermission'],
        ]);

        register_rest_route($namespace, '/pos/held', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'held'],
                'permission_callback' => [$this, 'devicePermission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'hold'],
                'permission_callback' => [$this, 'devicePermission'],
            ],
        ]);

        register_rest_route($namespace, '/pos/held/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'cancelHeld'],
            'permission_callback' => [$this, 'devicePermission'],
        ]);
    }

    /** Permission callback for every device-token route. */
    public function devicePermission(\WP_REST_Request $request): bool|\WP_Error
    {
        $device = $this->devices->authenticate((string) $request->get_header('device'));

        if ($device === null) {
            return new \WP_Error('invalid_device', 'This device is not registered or was revoked.', ['status' => 401]);
        }

        $request->set_param('_device', $device);

        return true;
    }

    public function devices(): \WP_REST_Response
    {
        return new \WP_REST_Response(['data' => $this->devices->all()], 200);
    }

    public function registerDevice(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $body = (array) $request->get_json_params();
            $result = $this->devices->register($body, Api::staffActor($request));

            // The plain token is shown once and never again (§15.1); it travels
            // inside `data` so the app's single response helper sees it.
            return new \WP_REST_Response([
                'data' => [...$result['device'], 'token' => $result['token']],
                'token' => $result['token'],
            ], 201);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function revoke(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            return new \WP_REST_Response([
                'data' => $this->devices->revoke((string) $request['uuid'], Api::staffActor($request)),
            ], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function staff(): \WP_REST_Response
    {
        if (!function_exists('get_users')) {
            return new \WP_REST_Response(['data' => []], 200);
        }

        $roles = [];
        foreach (['owner', 'manager', 'sales', 'cashier', 'uploader', 'dispatcher'] as $role) {
            $roles[] = Capabilities::roleSlug($role);
        }

        $users = get_users([
            'role__in' => $roles,
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);

        $data = [];
        foreach ($users as $user) {
            $data[] = [
                'user_id' => (int) $user->ID,
                'name' => (string) $user->display_name,
                'has_pin' => $this->devices->hasPin((int) $user->ID),
            ];
        }

        return new \WP_REST_Response(['data' => $data], 200);
    }

    public function setPin(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $body = (array) $request->get_json_params();
            $this->devices->setPin(
                (int) ($body['user_id'] ?? 0),
                (string) ($body['pin'] ?? ''),
                Api::staffActor($request)
            );

            return new \WP_REST_Response(['data' => ['ok' => true]], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function verifyPin(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        /** @var array<string,mixed> $device */
        $device = (array) $request->get_param('_device');
        $body = (array) $request->get_json_params();
        $userId = (int) ($body['user_id'] ?? 0);

        if ($userId <= 0) {
            return new \WP_Error('invalid_cashier', 'Choose a cashier.', ['status' => 422]);
        }

        $result = $this->devices->verifyPin($userId, (string) ($body['pin'] ?? ''));

        return new \WP_REST_Response([
            'data' => [
                'ok' => $result['ok'],
                'reason' => $result['reason'],
                'device_uuid' => (string) $device['uuid'],
            ],
        ], $result['ok'] ? 200 : 422);
    }

    public function sale(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->runDevice($request, fn (array $device, Actor $actor, array $body): array => $this->counter->sale($body, $device, $actor));
    }

    public function counterReturn(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->runDevice($request, fn (array $device, Actor $actor, array $body): array => $this->counter->counterReturn($body, $device, $actor));
    }

    public function cashUp(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->runDevice($request, fn (array $device, Actor $actor, array $body): array => $this->counter->cashUp($body, $device, $actor));
    }

    public function hold(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->runDevice($request, fn (array $device, Actor $actor, array $body): array => $this->counter->hold($body, $device, $actor));
    }

    public function held(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        /** @var array<string,mixed> $device */
        $device = (array) $request->get_param('_device');

        return new \WP_REST_Response(['data' => $this->counter->held((int) $device['id'])], 200);
    }

    public function cancelHeld(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        /** @var array<string,mixed> $device */
        $device = (array) $request->get_param('_device');

        try {
            return new \WP_REST_Response([
                'data' => $this->counter->cancelHeld((int) $request['id'], $device, $this->actorFor($request)),
            ], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    /**
     * @param callable(array<string,mixed>,Actor,array<string,mixed>):array<string,mixed> $work
     */
    private function runDevice(\WP_REST_Request $request, callable $work): \WP_REST_Response|\WP_Error
    {
        /** @var array<string,mixed> $device */
        $device = (array) $request->get_param('_device');

        try {
            $body = (array) $request->get_json_params();

            return new \WP_REST_Response([
                'data' => $work($device, $this->actorFor($request), $body),
            ], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    private function actorFor(\WP_REST_Request $request): Actor
    {
        /** @var array<string,mixed> $device */
        $device = (array) $request->get_param('_device');
        $body = (array) $request->get_json_params();
        $cashierId = (int) ($body['cashier_user_id'] ?? 0);

        return $cashierId > 0 ? Actor::staff($cashierId) : Actor::device((int) $device['id']);
    }
}
