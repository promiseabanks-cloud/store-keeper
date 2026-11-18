<?php

declare(strict_types=1);

namespace StoreKeeper\Platform\Plans;

use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Plans live in network tables (§17.3): one row carries both hard limits and
 * feature flags as JSON, so a plan change needs no schema change.
 */
final class PlanRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(bool $onlyActive = false): array
    {
        $sql = sprintf('SELECT * FROM %splans', $this->db->networkPrefix());
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY monthly_kobo ASC, id ASC';

        return array_map([$this, 'hydrate'], $this->db->select($sql));
    }

    /** @return array<string,mixed>|null */
    public function find(int $planId): ?array
    {
        $row = $this->db->first(
            sprintf('SELECT * FROM %splans WHERE id = ?', $this->db->networkPrefix()),
            [$planId]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /** @return array<string,mixed>|null */
    public function byCode(string $code): ?array
    {
        $row = $this->db->first(
            sprintf('SELECT * FROM %splans WHERE code = ?', $this->db->networkPrefix()),
            [$code]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert($this->db->networkPrefix() . 'plans', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $planId, array $data): void
    {
        $this->db->update($this->db->networkPrefix() . 'plans', $data, ['id' => $planId]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['monthly_kobo'] = (int) $row['monthly_kobo'];
        $row['setup_fee_kobo'] = (int) $row['setup_fee_kobo'];
        $row['annual_kobo'] = $row['annual_kobo'] === null ? null : (int) $row['annual_kobo'];
        $row['limits'] = is_string($row['limits'] ?? null) ? (json_decode($row['limits'], true) ?: []) : ($row['limits'] ?? []);
        $row['features'] = is_string($row['features'] ?? null) ? (json_decode($row['features'], true) ?: []) : ($row['features'] ?? []);
        $row['is_public'] = (int) ($row['is_public'] ?? 0);
        $row['is_active'] = (int) ($row['is_active'] ?? 0);

        return $row;
    }
}
