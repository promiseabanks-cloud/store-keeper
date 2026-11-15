<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Rest;

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Capabilities;
use StoreKeeper\Commerce\Core\Exception\NotFound;
use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;
use StoreKeeper\Commerce\Core\Uuid;
use StoreKeeper\Commerce\Customers\CustomerService;
use StoreKeeper\Commerce\Orders\OrderRepository;
use StoreKeeper\Commerce\Orders\OrderService;

/**
 * Order endpoints (§11.2, §12): list, create, read, take payment and cancel.
 */
final class OrderController
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderRepository $repository,
        private readonly CustomerService $customers,
        private readonly Api $api,
    ) {
    }

    public function register(): void
    {
        $namespace = Brand::REST_NAMESPACE;

        register_rest_route($namespace, '/orders', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'index'],
                'permission_callback' => Api::requireCapability(Capabilities::CREATE_ORDERS),
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create'],
                'permission_callback' => Api::requireCapability(Capabilities::CREATE_ORDERS),
            ],
        ]);

        register_rest_route($namespace, '/orders/(?P<uuid>[0-9a-fA-F-]{36})', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'show'],
                'permission_callback' => Api::requireCapability(Capabilities::CREATE_ORDERS),
            ],
        ]);

        register_rest_route($namespace, '/orders/(?P<uuid>[0-9a-fA-F-]{36})/payments', [
            'methods' => 'POST',
            'callback' => [$this, 'pay'],
            'permission_callback' => Api::requireCapability(Capabilities::CONFIRM_PAYMENTS),
        ]);

        register_rest_route($namespace, '/orders/(?P<uuid>[0-9a-fA-F-]{36})/cancel', [
            'methods' => 'POST',
            'callback' => [$this, 'cancel'],
            'permission_callback' => Api::requireCapability(Capabilities::CANCEL_ORDERS),
        ]);
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $rows = $this->repository->listDetailed([
            'status' => $request->get_param('status') ?: null,
            'payment_status' => $request->get_param('payment_status') ?: null,
            'search' => $request->get_param('search') ?: null,
        ], min(100, max(1, (int) ($request->get_param('limit') ?: 50))));

        $data = array_map(static fn (array $row): array => [
            'uuid' => (string) $row['uuid'],
            'number' => (string) $row['number'],
            'status' => (string) $row['status'],
            'payment_status' => (string) $row['payment_status'],
            'fulfilment_status' => (string) $row['fulfilment_status'],
            'channel' => (string) $row['channel'],
            'customer_name' => $row['customer_name'] === null ? null : (string) $row['customer_name'],
            'customer_phone' => $row['customer_phone'] === null ? null : (string) $row['customer_phone'],
            'total_kobo' => (int) $row['total_kobo'],
            'paid_kobo' => (int) $row['paid_kobo'],
            'created_at' => (string) $row['created_at'],
        ], $rows);

        return new \WP_REST_Response(['data' => $data], 200);
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $order = $this->orders->findByUuid((string) $request['uuid']);
            if ($order === null) {
                throw new NotFound('order', (string) $request['uuid']);
            }

            $orderId = (int) $order['id'];

            return new \WP_REST_Response([
                'data' => [
                    'order' => $order,
                    'items' => $this->orders->items($orderId),
                    'payments' => $this->orders->payments($orderId),
                ],
            ], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        if (!is_array($body) || empty($body['lines']) || !is_array($body['lines'])) {
            return new \WP_Error('validation_failed', 'lines are required.', ['status' => 422]);
        }

        $actor = Api::staffActor($request);

        try {
            $customerId = $this->resolveCustomerId($body, $actor);
            $key = (string) ($request->get_header('idempotency-key') ?: ($body['idempotency_key'] ?? ''));

            if ($key === '') {
                $key = Uuid::v4();
            }

            $result = $this->orders->create([
                'channel' => $body['channel'] ?? 'counter',
                'customer_id' => $customerId,
                'lines' => $body['lines'],
                'payment_method' => $body['payment_method'] ?? 'bank_transfer',
                'handed_over_now' => !empty($body['handed_over_now']),
                'location_id' => $body['location_id'] ?? null,
                'discount_kobo' => (int) ($body['discount_kobo'] ?? 0),
                'customer_note' => $body['customer_note'] ?? null,
                'allow_manual_price' => current_user_can(Capabilities::wp(Capabilities::OVERRIDE_PRICE)),
                'idempotency_key' => $key,
            ], $actor);

            return new \WP_REST_Response([
                'data' => [
                    'order' => $result['order'],
                    'items' => $result['items'],
                    'replayed' => $result['replayed'],
                ],
            ], 201);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function pay(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];
        $actor = Api::staffActor($request);

        try {
            $order = $this->orders->findByUuid((string) $request['uuid']);
            if ($order === null) {
                throw new NotFound('order', (string) $request['uuid']);
            }

            $updated = $this->orders->recordPayment((int) $order['id'], $body, $actor);

            return new \WP_REST_Response(['data' => $updated], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function cancel(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        $reason = is_array($body) ? (string) ($body['reason'] ?? 'Cancelled by staff') : 'Cancelled by staff';
        $actor = Api::staffActor($request);

        try {
            $order = $this->orders->findByUuid((string) $request['uuid']);
            if ($order === null) {
                throw new NotFound('order', (string) $request['uuid']);
            }

            return new \WP_REST_Response(['data' => $this->orders->cancel((int) $order['id'], $reason, $actor)], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    /**
     * A customer id, or a new/known customer created from the name + phone the
     * staff member typed. Phone is the natural key for a WhatsApp/counter sale.
     *
     * @param array<string,mixed> $body
     */
    private function resolveCustomerId(array $body, \StoreKeeper\Commerce\Core\Audit\Actor $actor): ?int
    {
        if (!empty($body['customer_id'])) {
            return (int) $body['customer_id'];
        }

        $customer = $body['customer'] ?? null;
        if (!is_array($customer)) {
            return null;
        }

        $phone = trim((string) ($customer['phone'] ?? ''));
        if ($phone === '') {
            return null;
        }

        $existing = $this->customers->findByPhone($phone);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $created = $this->customers->create([
            'name' => (string) ($customer['name'] ?? 'Walk-in customer'),
            'phone' => $phone,
            'email' => $customer['email'] ?? null,
            'business_name' => $customer['business_name'] ?? null,
        ], $actor, 'staff');

        return (int) $created['id'];
    }
}
