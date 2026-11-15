<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Support;

use PDO;
use StoreKeeper\Commerce\Core\Database\PdoConnection;
use StoreKeeper\Commerce\Core\SchemaMigrator;

/**
 * Real MariaDB/MySQL for integration and concurrency tests (D-004).
 *
 * Env: SK_TEST_DSN, SK_TEST_DB_USER, SK_TEST_DB_PASS, optionally SK_TEST_PREFIX.
 */
final class TestDatabase
{
    public const DEFAULT_PREFIX = 'wp_1_storekeeper_';

    public const DEFAULT_NETWORK_PREFIX = 'wp_storekeeper_';

    public static function dsn(): string
    {
        return getenv('SK_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3307;dbname=storekeeper_test;charset=utf8mb4';
    }

    public static function pdo(): PDO
    {
        return new PDO(
            self::dsn(),
            getenv('SK_TEST_DB_USER') ?: 'root',
            getenv('SK_TEST_DB_PASS') !== false ? (string) getenv('SK_TEST_DB_PASS') : '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /** A connection without touching the schema. */
    public static function connection(
        string $storePrefix = self::DEFAULT_PREFIX,
        string $networkPrefix = self::DEFAULT_NETWORK_PREFIX,
    ): PdoConnection {
        return new PdoConnection(self::pdo(), $storePrefix, $networkPrefix);
    }

    /** Drop every table in the test database, then run all migrations. */
    public static function fresh(
        string $storePrefix = self::DEFAULT_PREFIX,
        string $networkPrefix = self::DEFAULT_NETWORK_PREFIX,
    ): PdoConnection {
        $pdo = self::pdo();
        self::dropAllTables($pdo);

        $connection = new PdoConnection($pdo, $storePrefix, $networkPrefix);
        (new SchemaMigrator($connection, dirname(__DIR__, 2) . '/migrations'))->migrateAll();

        return $connection;
    }

    /** Remove every business row but keep the schema (faster per test). */
    public static function truncate(PdoConnection $connection): void
    {
        $pdo = $connection->pdo();
        $prefixes = [$connection->prefix(), $connection->networkPrefix()];
        $tables = [];

        foreach ($prefixes as $prefix) {
            $statement = $pdo->prepare(
                'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?'
            );
            $statement->execute([str_replace('_', '\\_', $prefix) . '%']);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $tables[] = (string) $table;
            }
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            if (str_ends_with($table, 'schema_migrations')) {
                continue;
            }
            $pdo->exec('TRUNCATE TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public static function tableExists(PdoConnection $connection, string $table): bool
    {
        return $connection->scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        ) > 0;
    }

    private static function dropAllTables(PDO $pdo): void
    {
        $statement = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()');
        $statement->execute();
        $tables = $statement->fetchAll(PDO::FETCH_COLUMN);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
