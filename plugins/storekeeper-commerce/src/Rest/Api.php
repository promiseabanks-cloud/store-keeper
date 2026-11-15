<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Rest;

use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Audit\AuditLogger;
use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Capabilities;
use StoreKeeper\Commerce\Core\Idempotency\IdempotencyResult;
use StoreKeeper\Commerce\Core\Idempotency\IdempotencyService;

/**
 * REST skeleton (§18, §22.2 Phase 1).
 *
 * Phase 1 ships the conventions every endpoint needs — capability auth,
 * idempotent writes and the error format — so Phase 2 controllers only declare
 * routes and delegate to the engine. No route is registered yet; they arrive
 * with the screens that call them.
 */
final class Api
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditLogger $audit,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            // Namespace-wide requirements for every future route.
            add_filter('rest_post_dispatch', static function ($result, $server, $request) {
                if (str_starts_with((string) $request->get_route(), '/' . Brand::REST_NAMESPACE)) {
                    $result->header('Cache-Control', 'no-store, private');
                }

                return $result;
            }, 10, 3);
        });
    }

    /**
     * permission_callback for staff routes (§19.1): capability first, no
     * __return_true anywhere except the token-checked tracking page and
     * signature-checked webhooks.
     */
    public static function requireCapability(string $capability): callable
    {
        return static function () use ($capability): bool|\WP_Error {
            if (!is_user_logged_in()) {
                return new \WP_Error('unauthorized', 'Sign in to continue.', ['status' => 401]);
            }

            if (!current_user_can(Capabilities::wp($capability))) {
                return new \WP_Error('forbidden', 'You do not have permission to do this.', ['status' => 403]);
            }

            return true;
        };
    }

    /** The actor behind the current staff request, for audit entries. */
    public static function staffActor(?\WP_REST_Request $request = null): Actor
    {
        $userId = get_current_user_id();
        $ip = $request !== null ? (string) ($_SERVER['REMOTE_ADDR'] ?? '') : null;

        return Actor::staff($userId, $ip === '' ? null : $ip);
    }

    /**
     * Run a write through the idempotency store (§18). Controllers call this
     * with the request body so a retried request never repeats its effect.
     *
     * @param array<string,mixed> $body
     * @param callable():array<string,mixed> $work
     */
    public function idempotent(
        \WP_REST_Request $request,
        string $scope,
        array $body,
        callable $work,
        int $status = 200,
    ): IdempotencyResult {
        $key = (string) $request->get_header('idempotency-key');
        $actorKey = 'staff:' . get_current_user_id();

        return $this->idempotency->run($scope, $actorKey, $key, $body, $work, $status);
    }

    public function audit(): AuditLogger
    {
        return $this->audit;
    }
}
