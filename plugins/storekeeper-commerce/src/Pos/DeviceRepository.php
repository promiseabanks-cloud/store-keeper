<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Pos;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Reads and writes for registered POS devices (§15.1). Tokens are stored only
 * as a SHA-256 hash; the plain token exists once, in the registration answer.
 */
final class DeviceRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'devices', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $deviceId, array $data): void
    {
        $this->db->update($this->db->prefix() . 'devices', $data, ['id' => $deviceId]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $deviceId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sdevices WHERE id = ?', $this->db->prefix()),
            [$deviceId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sdevices WHERE uuid = ?', $this->db->prefix()),
            [$uuid]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByTokenHash(string $hash): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sdevices WHERE token_hash = ?', $this->db->prefix()),
            [$hash]
        );
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->select(
            sprintf(
                'SELECT d.*, l.name AS location_name
                   FROM %sdevices d
              LEFT JOIN %slocations l ON l.id = d.location_id
               ORDER BY d.created_at DESC, d.id DESC',
                $this->db->prefix(),
                $this->db->prefix()
            )
        );
    }

    /**
     * Active devices whose last successful sync is older than the cutoff.
     *
     * @return list<array<string,mixed>>
     */
    public function stale(string $cutoff): array
    {
        return $this->db->select(
            sprintf(
                "SELECT * FROM %sdevices
                  WHERE status = 'active'
                    AND (last_sync_at IS NULL OR last_sync_at < ?)"
                ,
                $this->db->prefix()
            ),
            [$cutoff]
        );
    }

    public function touchSeen(int $deviceId): void
    {
        $this->update($deviceId, ['last_seen_at' => $this->clock->nowString()]);
    }

    public function touchSynced(int $deviceId): void
    {
        $now = $this->clock->nowString();
        $this->update($deviceId, ['last_sync_at' => $now, 'last_seen_at' => $now]);
    }

    /** @return array<string,mixed>|null the cash-up row */
    public function cashUp(int $deviceId): ?array
    {
        return $this->db->first(
            sprintf(
                'SELECT * FROM %scash_ups WHERE device_id = ? ORDER BY business_date DESC, id DESC LIMIT 1',
                $this->db->prefix()
            ),
            [$deviceId]
        );
    }
}
