-- Separate database for PHPUnit (integration + concurrency tests), so tests
-- can drop and recreate tables without touching the development site.
CREATE DATABASE IF NOT EXISTS storekeeper_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
