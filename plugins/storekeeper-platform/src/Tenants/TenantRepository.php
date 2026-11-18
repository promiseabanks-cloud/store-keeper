<?php

declare(strict_types=1);

namespace StoreKeeper\Platform\Tenants;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Tenant records link a network row to a subsite (§17.2). One row per blog.
 */
final class TenantRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert($this->db->networkPrefix() . 'tenants', $data);
    }

    /** @return array<string,mixed>|null */
    public function find(int $tenantId): ?array
    {
        return $this->hydrate($this->db->first(
            sprintf('SELECT * FROM %stenants WHERE id = ?', $this->db->networkPrefix()),
            [$tenantId]
        ));
    }

    /** @return array<string,mixed>|null */
    public function byBlog(int $blogId): ?array
    {
        return $this->hydrate($this->db->first(
            sprintf('SELECT * FROM %stenants WHERE blog_id = ?', $this->db->networkPrefix()),
            [$blogId]
        ));
    }

    /** @return array<string,mixed>|null */
    public function byUuid(string $uuid): ?array
    {
        return $this->hydrate($this->db->first(
            sprintf('SELECT * FROM %stenants WHERE uuid = ?', $this->db->networkPrefix()),
            [$uuid]
        ));
    }

    /** @return list<array<string,mixed>> */
    public function all(?string $status = null, int $limit = 100): array
    {
        $sql = sprintf('SELECT * FROM %stenants', $this->db->networkPrefix());
        $params = [];

        if ($status !== null && $status !== '') {
            $sql .= ' WHERE status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ?';
        $params[] = $limit;

        return array_map([$this, 'hydrate'], $this->db->select($sql, $params));
    }

    /** @param array<string,mixed> $data */
    public function update(int $tenantId, array $data): void
    {
        $data['updated_at'] = $this->clock->nowString();
        $this->db->update($this->db->networkPrefix() . 'tenants', $data, ['id' => $tenantId]);
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    private function hydrate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['blog_id'] = (int) $row['blog_id'];
        $row['plan_id'] = (int) $row['plan_id'];
        $row['owner_user_id'] = (int) $row['owner_user_id'];

        return $row;
    }
}
