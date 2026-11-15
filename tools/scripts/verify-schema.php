<?php

declare(strict_types=1);

/**
 * Rebuild the test schema and print every table created.
 * Usage: SK_TEST_DSN=... php tools/scripts/verify-schema.php
 */

require dirname(__DIR__, 2) . '/plugins/storekeeper-commerce/tests/bootstrap.php';

use StoreKeeper\Commerce\Tests\Support\TestDatabase;

$connection = TestDatabase::fresh();
$pdo = $connection->pdo();

$store = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ? ORDER BY table_name');
$store->execute([str_replace('_', '\\_', $connection->prefix()) . '%']);
$storeTables = $store->fetchAll(PDO::FETCH_COLUMN);

$network = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ? ORDER BY table_name');
$network->execute([str_replace('_', '\\_', $connection->networkPrefix()) . '%']);
$networkTables = $network->fetchAll(PDO::FETCH_COLUMN);

printf("Store tables (%d):\n  %s\n", count($storeTables), implode("\n  ", $storeTables));
printf("\nNetwork tables (%d):\n  %s\n", count($networkTables), implode("\n  ", $networkTables));

$applied = $connection->select(sprintf('SELECT migration FROM %sschema_migrations ORDER BY migration', $connection->prefix()));
printf("\nMigrations applied: %s\n", implode(', ', array_column($applied, 'migration')));
printf("Total tables: %d\n", count($storeTables) + count($networkTables));
