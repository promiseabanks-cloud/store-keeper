<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Rest;

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Capabilities;

/**
 * Who works in this store and what they may do (§5.2).
 *
 * Read-only in this phase: WordPress itself owns user creation, and inviting a
 * colleague happens on the Users screen with one of the store roles. The tab
 * answers the question staff actually ask — "who else can confirm payments?".
 */
final class StaffController
{
    public function register(): void
    {
        register_rest_route(Brand::REST_NAMESPACE, '/staff', [
            'methods' => 'GET',
            'callback' => [$this, 'index'],
            'permission_callback' => Api::requireCapability(Capabilities::MANAGE_STAFF),
        ]);
    }

    public function index(): \WP_REST_Response
    {
        $slugs = [];
        foreach (array_keys(Capabilities::roleLabels()) as $role) {
            $slugs[] = Capabilities::roleSlug($role);
        }

        // A site administrator is the store owner in practice, and must not be
        // missing from the only screen that lists who can approve money.
        $slugs[] = 'administrator';

        $users = get_users([
            'role__in' => $slugs,
            'number' => 100,
            'orderby' => 'display_name',
        ]);

        $roles = [];
        foreach (Capabilities::roleLabels() as $role => $label) {
            $roles[] = ['slug' => $role, 'label' => $label, 'wp_role' => Capabilities::roleSlug($role)];
        }

        $data = array_map(static function ($user) use ($slugs): array {
            $userRoles = array_values(array_intersect((array) $user->roles, $slugs));
            $role = null;

            if ($userRoles !== []) {
                $role = (string) $userRoles[0] === 'administrator'
                    ? Capabilities::ROLE_OWNER
                    : substr((string) $userRoles[0], strlen(Brand::CAPABILITY_PREFIX));
            }

            // The role matrix is the source of truth, so the answer does not
            // depend on which site in the network the capability was read from.
            $grants = $role === null ? [] : Capabilities::roleCapabilities($role);

            return [
                'id' => (int) $user->ID,
                'name' => (string) $user->display_name,
                'email' => (string) $user->user_email,
                'role' => $role,
                'is_owner' => $role === Capabilities::ROLE_OWNER,
                'can_confirm_payments' => !empty($grants[Capabilities::wp(Capabilities::CONFIRM_PAYMENTS)]),
            ];
        }, $users);

        return new \WP_REST_Response(['data' => ['staff' => $data, 'roles' => $roles]], 200);
    }
}
