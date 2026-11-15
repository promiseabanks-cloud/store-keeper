<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Rest;

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Capabilities;
use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;
use StoreKeeper\Commerce\Core\Money;
use StoreKeeper\Commerce\Customers\CustomerService;
use StoreKeeper\Commerce\Pricing\PricingRepository;
use StoreKeeper\Commerce\Pricing\PricingService;

/**
 * Price lists and quantity tiers (§10.1, §11.5).
 *
 * Staff edit the list a group is priced by; the resolution order itself lives
 * in PricingService and is never re-implemented here.
 */
final class PricingController
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly PricingRepository $repository,
        private readonly CustomerService $customers,
        private readonly Api $api,
    ) {
    }

    public function register(): void
    {
        $namespace = Brand::REST_NAMESPACE;

        register_rest_route($namespace, '/price-lists', [
            'methods' => 'GET',
            'callback' => [$this, 'lists'],
            'permission_callback' => Api::requireCapability(Capabilities::EDIT_PRICES),
        ]);

        register_rest_route($namespace, '/price-lists/(?P<id>\d+)/prices', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'prices'],
                'permission_callback' => Api::requireCapability(Capabilities::EDIT_PRICES),
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'setPrice'],
                'permission_callback' => Api::requireCapability(Capabilities::EDIT_PRICES),
            ],
        ]);

        register_rest_route($namespace, '/pricing/quote', [
            'methods' => 'POST',
            'callback' => [$this, 'quote'],
            'permission_callback' => Api::requireCapability(Capabilities::EDIT_PRICES),
        ]);
    }

    public function lists(): \WP_REST_Response
    {
        $lists = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'currency' => (string) $row['currency'],
            'is_base' => (int) $row['is_base'],
        ], $this->repository->lists());

        return new \WP_REST_Response(['data' => $lists], 200);
    }

    public function prices(\WP_REST_Request $request): \WP_REST_Response
    {
        $rows = $this->repository->pricesForList(
            (int) $request['id'],
            (string) ($request->get_param('search') ?: ''),
            min(500, max(1, (int) ($request->get_param('limit') ?: 200)))
        );

        $data = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'variant_id' => (int) $row['variant_id'],
            'pack_unit_id' => $row['pack_unit_id'] === null ? null : (int) $row['pack_unit_id'],
            'pack_unit_name' => $row['pack_unit_name'] === null ? null : (string) $row['pack_unit_name'],
            'min_qty' => (int) $row['min_qty'],
            'amount_kobo' => (int) $row['amount_kobo'],
            'sku' => $row['sku'] === null ? null : (string) $row['sku'],
            'product_name' => (string) $row['product_name'],
            'variant_title' => (string) $row['variant_title'],
        ], $rows);

        return new \WP_REST_Response(['data' => $data], 200);
    }

    public function setPrice(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        if (!is_array($body) || empty($body['variant_id']) || !isset($body['amount_kobo'])) {
            return new \WP_Error('validation_failed', 'variant_id and amount_kobo are required.', ['status' => 422]);
        }

        try {
            $priceId = $this->pricing->setPrice(
                (int) $request['id'],
                (int) $body['variant_id'],
                isset($body['pack_unit_id']) && $body['pack_unit_id'] !== null ? (int) $body['pack_unit_id'] : null,
                max(1, (int) ($body['min_qty'] ?? 1)),
                new Money((int) $body['amount_kobo']),
                Api::staffActor($request),
            );

            return new \WP_REST_Response(['data' => ['id' => $priceId]], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    /**
     * What a given customer (or a guest) would pay for a variant right now.
     * This is the staff's check that a wholesale customer sees wholesale.
     */
    public function quote(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        if (!is_array($body) || empty($body['variant_id'])) {
            return new \WP_Error('validation_failed', 'variant_id is required.', ['status' => 422]);
        }

        try {
            $customer = null;
            if (!empty($body['customer_uuid'])) {
                $customer = $this->customers->findByUuid((string) $body['customer_uuid']);
            }

            $resolved = $this->pricing->resolve(
                (int) $body['variant_id'],
                isset($body['pack_unit_id']) && $body['pack_unit_id'] !== null ? (int) $body['pack_unit_id'] : null,
                max(1, (int) ($body['qty'] ?? 1)),
                $customer,
            );

            return new \WP_REST_Response(['data' => $resolved->toArray()], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }
}
