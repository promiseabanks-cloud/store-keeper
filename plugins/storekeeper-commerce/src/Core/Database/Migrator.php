<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Database;

use RuntimeException;

/**
 * Versioned migration runner (§7.3, D-003).
 *
 * Each file in the directory returns: static fn (string $prefix): array<int,string>
 * where every statement may contain {p}, replaced with the given prefix.
 * Applied files are recorded in {prefix}schema_migrations so a migration runs once.
 */
final class Migrator
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $directory,
        private readonly string $prefix,
    ) {
    }

    /** @return list<string> names of migrations applied in this run */
    public function migrate(): array
    {
        $this->ensureBookkeepingTable();

        $applied = array_column(
            $this->db->select(sprintf('SELECT migration FROM %sschema_migrations', $this->prefix)),
            'migration'
        );

        $files = glob(rtrim($this->directory, '/') . '/*.php') ?: [];
        sort($files);
        $appliedNow = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $applied, true)) {
                continue;
            }

            $migration = require $file;
            if (!is_callable($migration)) {
                throw new RuntimeException(sprintf('Migration %s must return a callable.', $name));
            }

            /** @var list<string> $statements */
            $statements = $migration($this->prefix);
            foreach ($statements as $statement) {
                $this->db->execute(str_replace('{p}', $this->prefix, $statement));
            }

            $this->db->insert($this->prefix . 'schema_migrations', [
                'migration' => $name,
                'applied_at' => gmdate('Y-m-d H:i:s.v'),
            ]);
            $appliedNow[] = $name;
        }

        return $appliedNow;
    }

    private function ensureBookkeepingTable(): void
    {
        $this->db->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS %sschema_migrations (
                migration VARCHAR(191) NOT NULL PRIMARY KEY,
                applied_at DATETIME(3) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->prefix
        ));
    }
}
