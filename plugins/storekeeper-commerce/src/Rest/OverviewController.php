<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Rest;

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Capabilities;
use StoreKeeper\Commerce\Reports\OverviewService;

/**
 * GET /overview — the back office home screen numbers (§11.7).
 */
final class OverviewController
{
    public function __construct(private readonly OverviewService $overview)
    {
    }

    public function register(): void
    {
        register_rest_route(Brand::REST_NAMESPACE, '/overview', [
            'methods' => 'GET',
            'callback' => [$this, 'show'],
            'permission_callback' => Api::requireCapability(Capabilities::VIEW_REVENUE),
        ]);
    }

    public function show(): \WP_REST_Response
    {
        return new \WP_REST_Response(['data' => $this->overview->overview()], 200);
    }
}
