<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Rest;

use StoreKeeper\Commerce\Catalog\CatalogRepository;
use StoreKeeper\Commerce\Catalog\CatalogService;
use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Capabilities;
use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;

/**
 * Catalog endpoints (§18). Controllers validate transport concerns, check the
 * capability, then delegate to the engine; they never contain business logic.
 */
final class CatalogController
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly CatalogRepository $repository,
        private readonly Api $api,
    ) {
    }

    public function register(): void
    {
        $namespace = Brand::REST_NAMESPACE;

        register_rest_route($namespace, '/products', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'index'],
                'permission_callback' => Api::requireCapability(Capabilities::EDIT_PRODUCTS),
                'args' => [
                    'search' => ['type' => 'string', 'required' => false],
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 50],
                ],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create'],
                'permission_callback' => Api::requireCapability(Capabilities::EDIT_PRODUCTS),
            ],
        ]);

        register_rest_route($namespace, '/products/(?P<uuid>[0-9a-fA-F-]{36})', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'show'],
                'permission_callback' => Api::requireCapability(Capabilities::EDIT_PRODUCTS),
            ],
        ]);
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $products = $this->repository->listWithStock(
            $request->get_param('search'),
            min(200, max(1, (int) $request->get_param('limit')))
        );

        return new \WP_REST_Response(['data' => $products, 'next_cursor' => null], 200);
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $product = $this->catalog->productByUuid((string) $request['uuid']);
        if ($product === null) {
            return new \WP_Error('not_found', 'Product not found.', ['status' => 404]);
        }

        $variants = $this->catalog->variants((int) $product['id']);
        $media = [];

        return new \WP_REST_Response([
            'data' => [
                'product' => $product,
                'variants' => $variants,
                'media' => $media,
            ],
        ], 200);
    }

    public function create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new \WP_Error('validation_failed', 'A JSON body is required.', ['status' => 422]);
        }

        try {
            $result = $this->api->idempotent(
                $request,
                'catalog.products.create',
                $body,
                fn (): array => $this->catalog->createProduct($body, Api::staffActor($request)),
                201
            );

            return new \WP_REST_Response($result->response, $result->statusCode, [
                'Idempotency-Replayed' => $result->replayed ? 'true' : 'false',
            ]);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }
}
