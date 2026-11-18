<?php

declare(strict_types=1);

namespace StoreKeeper\Platform\Plans;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Usage counters per tenant, metric and month (§17.3).
 */
final class UsageRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    public function increment(int $tenantId, string $metric, int $by = 1, ?string $period = null): int
    {
        $period = $period ?? $this->clock->now()->format('Y-m');
        $now = $this->clock->nowString();
        $table = $this->db->networkPrefix() . 'usage_counters';

        $this->db->execute(
            "INSERT INTO {$table} (tenant_id, metric, period, value, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = value + VALUES(value), updated_at = VALUES(updated_at)",
            [$tenantId, $metric, $period, $by, $now]
        );

        return $this->value($tenantId, $metric, $period);
    }

    public function value(int $tenantId, string $metric, ?string $period = null): int
    {
        $period = $period ?? $this->clock->now()->format('Y-m');

        return (int) $this->db->scalar(
            sprintf(
                'SELECT COALESCE(value, 0) FROM %susage_counters WHERE tenant_id = ? AND metric = ? AND period = ?',
                $this->db->networkPrefix()
            ),
            [$tenantId, $metric, $period]
        );
    }

    /** @return list<array<string,mixed>> */
    public function forTenant(int $tenantId, ?string $period = null): array
    {
        $period = $period ?? $this->clock->now()->format('Y-m');

        return $this->db->select(
            sprintf(
                'SELECT metric, period, value FROM %susage_counters WHERE tenant_id = ? AND period = ? ORDER BY metric ASC',
                $this->db->networkPrefix()
            ),
            [$tenantId, $period]
        );
    }
}
