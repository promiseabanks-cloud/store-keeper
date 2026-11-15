<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core;

use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Database\Migrator;

/**
 * Runs the store and network migration sets for one site (§17.2 step 3, §8).
 */
final class SchemaMigrator
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $migrationsRoot,
    ) {
    }

    /** @return array{store:list<string>,network:list<string>} */
    public function migrateAll(): array
    {
        return [
            'store' => (new Migrator($this->db, $this->migrationsRoot . '/store', $this->db->prefix()))->migrate(),
            'network' => (new Migrator($this->db, $this->migrationsRoot . '/network', $this->db->networkPrefix()))->migrate(),
        ];
    }
}
