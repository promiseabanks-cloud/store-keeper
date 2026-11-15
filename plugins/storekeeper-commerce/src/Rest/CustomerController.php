<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Rest;

use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Capabilities;
use StoreKeeper\Commerce\Core\Exception\NotFound;
use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;
use StoreKeeper\Commerce\Core\Money;
use StoreKeeper\Commerce\Customers\CustomerRepository;
use StoreKeeper\Commerce\Customers\CustomerService;
use StoreKeeper\Commerce\Orders\OrderRepository;

/**
 * Customers and wholesale approval (§10.2, §11.1, §11.5).
 *
 * A customer is not a WordPress user (§7.4): these rows are the shop's
 * address book, and a group decides which price list they see.
 */
final class CustomerController
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly CustomerRepository $repository,
        private readonly OrderRepository $orders,
        private readonly Api $api,
    ) {
    }

    public function register(): void
    {
        $namespace = Brand::REST_NAMESPACE;
        $uuid = '(?P<uuid>[0-9a-fA-F-]{36})';

        register_rest_route($namespace, '/customers', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'index'],
                'permission_callback' => Api::requireCapability(Capabilities::MANAGE_CUSTOMERS),
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create'],
                'permission_callback' => Api::requireCapability(Capabilities::MANAGE_CUSTOMERS),
            ],
        ]);

        register_rest_route($namespace, '/customer-groups', [
            'methods' => 'GET',
            'callback' => [$this, 'groups'],
            'permission_callback' => Api::requireCapability(Capabilities::MANAGE_CUSTOMERS),
        ]);

        register_rest_route($namespace, '/customers/' . $uuid, [
            'methods' => 'GET',
            'callback' => [$this, 'show'],
            'permission_callback' => Api::requireCapability(Capabilities::MANAGE_CUSTOMERS),
        ]);

        register_rest_route($namespace, '/customers/' . $uuid . '/approve', [
            'methods' => 'POST',
            'callback' => [$this, 'approve'],
            'permission_callback' => Api::requireCapability(Capabilities::APPROVE_WHOLESALE),
        ]);

        register_rest_route($namespace, '/customers/' . $uuid . '/reject', [
            'methods' => 'POST',
            'callback' => [$this, 'reject'],
            'permission_callback' => Api::requireCapability(Capabilities::APPROVE_WHOLESALE),
        ]);

        register_rest_route($namespace, '/customers/' . $uuid . '/group', [
            'methods' => 'POST',
            'callback' => [$this, 'changeGroup'],
            'permission_callback' => Api::requireCapability(Capabilities::APPROVE_WHOLESALE),
        ]);

        register_rest_route($namespace, '/customers/' . $uuid . '/prices', [
            'methods' => 'PUT',
            'callback' => [$this, 'setPrice'],
            'permission_callback' => Api::requireCapability(Capabilities::EDIT_PRICES),
        ]);
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $rows = $this->repository->listWithGroup([
            'search' => $request->get_param('search') ?: null,
            'approval_status' => $request->get_param('approval_status') ?: null,
            'group_id' => $request->get_param('group_id') ?: null,
        ], min(200, max(1, (int) ($request->get_param('limit') ?: 50))));

        $data = array_map(static fn (array $row): array => [
            'uuid' => (string) $row['uuid'],
            'name' => (string) $row['name'],
            'business_name' => $row['business_name'] === null ? null : (string) $row['business_name'],
            'phone' => (string) $row['phone_e164'],
            'email' => $row['email'] === null ? null : (string) $row['email'],
            'group_id' => (int) $row['group_id'],
            'group_name' => $row['group_name'] === null ? null : (string) $row['group_name'],
            'approval_status' => (string) $row['approval_status'],
            'requested_group_id' => $row['requested_group_id'] === null ? null : (int) $row['requested_group_id'],
            'total_spent_kobo' => (int) $row['total_spent_kobo'],
            'order_count' => (int) $row['order_count'],
            'last_order_at' => $row['last_order_at'],
        ], $rows);

        return new \WP_REST_Response(['data' => $data], 200);
    }

    public function groups(): \WP_REST_Response
    {
        $groups = array_map(static fn (array $group): array => [
            'id' => (int) $group['id'],
            'name' => (string) $group['name'],
            'type' => (string) $group['type'],
            'price_list_id' => (int) $group['price_list_id'],
            'approval' => (string) $group['approval'],
            'is_default' => (int) $group['is_default'],
        ], $this->customers->groups());

        return new \WP_REST_Response(['data' => $groups], 200);
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $customer = $this->customers->findByUuid((string) $request['uuid']);
            if ($customer === null) {
                throw new NotFound('customer', (string) $request['uuid']);
            }

            $customerId = (int) $customer['id'];

            return new \WP_REST_Response([
                'data' => [
                    'customer' => $customer,
                    'orders' => $this->orders->listDetailed(['customer_id' => $customerId], 20),
                    'special_prices' => $this->customers->specialPrices($customerId),
                ],
            ], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new \WP_Error('validation_failed', 'A JSON body is required.', ['status' => 422]);
        }

        try {
            $customer = $this->customers->create([
                'name' => $body['name'] ?? '',
                'phone' => $body['phone'] ?? '',
                'business_name' => $body['business_name'] ?? null,
                'email' => $body['email'] ?? null,
                'group_id' => $body['group_id'] ?? null,
                'notes' => $body['notes'] ?? null,
            ], Api::staffActor($request), 'staff');

            return new \WP_REST_Response(['data' => $customer], 201);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function approve(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->onCustomer($request, fn (int $id, Actor $actor): array => $this->customers->approve($id, $actor));
    }

    public function reject(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        $reason = is_array($body) ? ($body['reason'] ?? null) : null;

        return $this->onCustomer(
            $request,
            fn (int $id, Actor $actor): array => $this->customers->reject($id, $actor, is_string($reason) ? $reason : null)
        );
    }

    public function changeGroup(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        $groupId = is_array($body) ? (int) ($body['group_id'] ?? 0) : 0;

        if ($groupId <= 0) {
            return new \WP_Error('validation_failed', 'group_id is required.', ['status' => 422]);
        }

        return $this->onCustomer(
            $request,
            fn (int $id, Actor $actor): array => $this->customers->changeGroup($id, $groupId, $actor)
        );
    }

    public function setPrice(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        if (!is_array($body) || empty($body['variant_id']) || !isset($body['amount_kobo'])) {
            return new \WP_Error('validation_failed', 'variant_id and amount_kobo are required.', ['status' => 422]);
        }

        try {
            $customer = $this->customers->findByUuid((string) $request['uuid']);
            if ($customer === null) {
                throw new NotFound('customer', (string) $request['uuid']);
            }

            $price = $this->customers->setSpecialPrice(
                (int) $customer['id'],
                (int) $body['variant_id'],
                isset($body['pack_unit_id']) ? (int) $body['pack_unit_id'] : null,
                new Money((int) $body['amount_kobo']),
                Api::staffActor($request),
                isset($body['valid_from']) ? (string) $body['valid_from'] : null,
                isset($body['valid_to']) ? (string) $body['valid_to'] : null,
            );

            return new \WP_REST_Response(['data' => $price], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    /** @param callable(int,Actor):array $work */
    private function onCustomer(\WP_REST_Request $request, callable $work): \WP_REST_Response|\WP_Error
    {
        try {
            $customer = $this->customers->findByUuid((string) $request['uuid']);
            if ($customer === null) {
                throw new NotFound('customer', (string) $request['uuid']);
            }

            return new \WP_REST_Response(
                ['data' => $work((int) $customer['id'], Api::staffActor($request))],
                200
            );
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }
}
