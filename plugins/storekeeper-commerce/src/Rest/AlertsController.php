<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Rest;

use StoreKeeper\Commerce\Alerts\AlertService;
use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Capabilities;
use StoreKeeper\Commerce\Core\Exception\NotFound;

/**
 * The alerts list (§9.9): what needs a merchant's attention, and closing it.
 *
 * Alerts are raised by the services themselves — oversell, low stock, payment
 * mismatch, failed webhook — so this controller only reads and resolves.
 */
final class AlertsController
{
    public function __construct(private readonly AlertService $alerts)
    {
    }

    public function register(): void
    {
        register_rest_route(Brand::REST_NAMESPACE, '/alerts', [
            'methods' => 'GET',
            'callback' => [$this, 'index'],
            'permission_callback' => Api::requireCapability(Capabilities::VIEW_REPORTS),
        ]);

        register_rest_route(Brand::REST_NAMESPACE, '/alerts/(?P<id>\d+)/resolve', [
            'methods' => 'POST',
            'callback' => [$this, 'resolve'],
            'permission_callback' => Api::requireCapability(Capabilities::RESOLVE_ALERTS),
        ]);
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $type = $request->get_param('type') ?: null;

        $rows = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'severity' => (string) $row['severity'],
            'subject_type' => $row['subject_type'],
            'subject_id' => $row['subject_id'] === null ? null : (int) $row['subject_id'],
            'payload' => json_decode((string) $row['payload'], true) ?: [],
            'created_at' => (string) $row['created_at'],
        ], $this->alerts->open(is_string($type) ? $type : null, 100));

        return new \WP_REST_Response(['data' => $rows], 200);
    }

    public function resolve(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $alertId = (int) $request['id'];
        $body = $request->get_json_params();
        $resolution = is_array($body) ? trim((string) ($body['resolution'] ?? '')) : '';

        if ($resolution === '') {
            $resolution = 'Resolved from the back office';
        }

        $this->alerts->resolve($alertId, get_current_user_id() ?: null, $resolution);

        return new \WP_REST_Response(['data' => ['id' => $alertId, 'status' => 'resolved']], 200);
    }
}
