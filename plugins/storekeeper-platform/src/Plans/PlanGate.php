<?php

declare(strict_types=1);

namespace StoreKeeper\Platform\Plans;

use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;

/**
 * Hard-limit and feature enforcement (§17.3).
 *
 * A limit of 0 means "not capped" so a plan can leave a metric unlimited.
 * Callers pass the current count; the gate never counts store rows itself, so it
 * stays usable from any tenant context (D-040).
 */
final class PlanGate
{
    public function __construct(private readonly PlanRepository $plans)
    {
    }

    /** @param array<string,mixed> $tenant @return array<string,mixed>|null */
    public function plan(array $tenant): ?array
    {
        return $this->plans->find((int) ($tenant['plan_id'] ?? 0));
    }

    /** @param array<string,mixed> $tenant */
    public function limit(array $tenant, string $metric): int
    {
        $plan = $this->plan($tenant);

        return (int) ($plan['limits'][$metric] ?? 0);
    }

    /**
     * @param array<string,mixed> $tenant
     * @param int $current how many exist now
     */
    public function assertCanAdd(array $tenant, string $metric, int $current, int $adding = 1): void
    {
        $limit = $this->limit($tenant, $metric);

        if ($limit > 0 && $current + $adding > $limit) {
            throw new PlanLimit($metric, $limit, $current + $adding);
        }
    }

    /** @param array<string,mixed> $tenant */
    public function feature(array $tenant, string $feature): bool
    {
        $plan = $this->plan($tenant);

        return !empty($plan['features'][$feature]);
    }

    /** @param array<string,mixed> $tenant */
    public function requireFeature(array $tenant, string $feature): void
    {
        if (!$this->feature($tenant, $feature)) {
            throw new StoreKeeperException('This plan does not include ' . str_replace('_', ' ', $feature) . '.');
        }
    }

    /** @param array<string,mixed> $tenant */
    public function allowsStoreMode(array $tenant, string $mode): bool
    {
        $plan = $this->plan($tenant);
        $modes = $plan['features']['store_modes'] ?? ['both'];

        return in_array($mode, $modes, true) || in_array('both', $modes, true);
    }
}
