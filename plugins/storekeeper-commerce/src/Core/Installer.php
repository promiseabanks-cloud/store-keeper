<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core;

use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Plugin activation work (§7.3): run pending migrations, register the store
 * roles and record the schema version. Safe to run repeatedly.
 */
final class Installer
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $migrationsRoot,
    ) {
    }

    /**
     * @return array{store:list<string>,network:list<string>}
     */
    public function activate(): array
    {
        $applied = (new SchemaMigrator($this->db, $this->migrationsRoot))->migrateAll();

        self::registerRoles();

        if (function_exists('update_option')) {
            update_option(Brand::SCHEMA_VERSION_OPTION, self::schemaVersion());
        }

        return $applied;
    }

    /**
     * Register (idempotently) every §5.2 role with its capabilities.
     */
    public static function registerRoles(): void
    {
        if (!function_exists('add_role')) {
            return;
        }

        foreach (Capabilities::roleLabels() as $role => $label) {
            $slug = Capabilities::roleSlug($role);
            $existing = get_role($slug);

            if ($existing === null) {
                add_role($slug, $label, Capabilities::roleCapabilities($role));
                continue;
            }

            foreach (Capabilities::roleCapabilities($role) as $capability => $granted) {
                if (!$existing->has_cap($capability)) {
                    $existing->add_cap($capability, $granted);
                }
            }
        }
    }

    /** Remove the store roles (used by uninstall, never by deactivation). */
    public static function unregisterRoles(): void
    {
        if (!function_exists('remove_role')) {
            return;
        }

        foreach (array_keys(Capabilities::roles()) as $role) {
            remove_role(Capabilities::roleSlug($role));
        }
    }

    /** The schema version is the newest migration file, e.g. "0005_system". */
    public static function schemaVersion(): string
    {
        $files = glob(dirname(__DIR__, 2) . '/migrations/store/*.php') ?: [];
        sort($files);

        return $files === [] ? '0' : basename((string) end($files), '.php');
    }
}
