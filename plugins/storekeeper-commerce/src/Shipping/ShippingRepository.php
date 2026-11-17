<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Shipping;

use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Zones, the areas they cover and the methods a zone offers (§8.4, §13.3).
 */
final class ShippingRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function zones(): array
    {
        return $this->db->select(
            sprintf('SELECT * FROM %sshipping_zones ORDER BY sort_order ASC, id ASC', $this->db->prefix())
        );
    }

    /** @return array<string,mixed>|null */
    public function zone(int $zoneId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sshipping_zones WHERE id = ?', $this->db->prefix()),
            [$zoneId]
        );
    }

    /** @return list<array{state:string,lga:?string}> */
    public function areas(int $zoneId): array
    {
        return $this->db->select(
            sprintf('SELECT state, lga FROM %sshipping_zone_areas WHERE zone_id = ? ORDER BY state ASC, lga ASC', $this->db->prefix()),
            [$zoneId]
        );
    }

    /** @param list<array{state:string,lga:?string}> $areas */
    public function createZone(string $name, array $areas, int $sortOrder): int
    {
        $zoneId = $this->db->insert($this->db->prefix() . 'shipping_zones', [
            'name' => $name,
            'sort_order' => $sortOrder,
        ]);

        foreach ($areas as $area) {
            $this->addArea($zoneId, (string) $area['state'], $area['lga']);
        }

        return $zoneId;
    }

    public function addArea(int $zoneId, string $state, ?string $lga): void
    {
        $this->db->insert($this->db->prefix() . 'shipping_zone_areas', [
            'zone_id' => $zoneId,
            'state' => $state,
            'lga' => $lga,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function methods(bool $activeOnly = false): array
    {
        return $this->db->select(
            sprintf(
                'SELECT * FROM %sshipping_methods %s ORDER BY sort_order ASC, id ASC',
                $this->db->prefix(),
                $activeOnly ? 'WHERE is_active = 1' : ''
            )
        );
    }

    /** @return array<string,mixed>|null */
    public function method(int $methodId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sshipping_methods WHERE id = ?', $this->db->prefix()),
            [$methodId]
        );
    }

    /** @param array<string,mixed> $data */
    public function createMethod(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'shipping_methods', $data);
    }

    /** @param array<string,mixed> $data */
    public function updateMethod(int $methodId, array $data): void
    {
        $this->db->update($this->db->prefix() . 'shipping_methods', $data, ['id' => $methodId]);
    }

    public function methodCount(): int
    {
        return (int) $this->db->scalar(
            sprintf('SELECT COUNT(*) FROM %sshipping_methods', $this->db->prefix())
        );
    }

    public function zoneCount(): int
    {
        return (int) $this->db->scalar(
            sprintf('SELECT COUNT(*) FROM %sshipping_zones', $this->db->prefix())
        );
    }

    public function maxZoneSortOrder(): int
    {
        return (int) $this->db->scalar(
            sprintf('SELECT COALESCE(MAX(sort_order), 0) FROM %sshipping_zones', $this->db->prefix())
        );
    }
}
