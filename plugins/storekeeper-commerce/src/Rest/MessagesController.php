<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Rest;

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Capabilities;
use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;
use StoreKeeper\Commerce\Notifications\MessageService;

/**
 * The Messages screen (§12.2, §12.4): read the outbox, send queued messages
 * now, and edit the store's template overrides.
 *
 * Reading is open to anyone who can create an order (a salesperson may need to
 * check whether a customer's confirmation went out); changing copy or forcing a
 * dispatch needs `manage_settings`.
 */
final class MessagesController
{
    public function __construct(
        private readonly MessageService $messages,
        private readonly Api $api,
    ) {
    }

    public function register(): void
    {
        $namespace = Brand::REST_NAMESPACE;

        register_rest_route($namespace, '/messages', [
            'methods' => 'GET',
            'callback' => [$this, 'index'],
            'permission_callback' => Api::requireCapability(Capabilities::CREATE_ORDERS),
        ]);

        register_rest_route($namespace, '/messages/dispatch', [
            'methods' => 'POST',
            'callback' => [$this, 'dispatch'],
            'permission_callback' => Api::requireCapability(Capabilities::MANAGE_SETTINGS),
        ]);

        register_rest_route($namespace, '/messages/templates', [
            'methods' => 'GET',
            'callback' => [$this, 'templates'],
            'permission_callback' => Api::requireCapability(Capabilities::CREATE_ORDERS),
        ]);

        register_rest_route($namespace, '/messages/templates/(?P<event>[a-z0-9_]+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'saveTemplate'],
            'permission_callback' => Api::requireCapability(Capabilities::MANAGE_SETTINGS),
        ]);
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $status = trim((string) ($request->get_param('status') ?? ''));
            $result = $this->messages->recent(
                $status === '' ? null : $status,
                (int) ($request->get_param('limit') ?: 100)
            );

            return new \WP_REST_Response([
                'data' => $result['rows'],
                'counts' => $result['counts'],
            ], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }

    public function dispatch(): \WP_REST_Response
    {
        return new \WP_REST_Response(['data' => $this->messages->dispatch()], 200);
    }

    public function templates(): \WP_REST_Response
    {
        return new \WP_REST_Response(['data' => $this->messages->templates()], 200);
    }

    public function saveTemplate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];

        try {
            return new \WP_REST_Response([
                'data' => $this->messages->saveTemplate(
                    (string) $request['event'],
                    $body,
                    Api::staffActor($request)
                ),
            ], 200);
        } catch (StoreKeeperException $error) {
            return ErrorMapper::toWpError($error);
        }
    }
}
