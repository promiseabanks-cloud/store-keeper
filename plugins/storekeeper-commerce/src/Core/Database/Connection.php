<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Database;

/**
 * The only way the engine reaches the database (D-002).
 *
 * SQL uses positional "?" placeholders; both drivers bind them safely.
 * Table names are already prefixed by the caller: store tables use prefix(),
 * network/platform tables use networkPrefix() (§6.4).
 */
interface Connection
{
    /** Per-store table prefix, e.g. "wp_2_storekeeper_". */
    public function prefix(): string;

    /** Network-level table prefix, e.g. "wp_storekeeper_". */
    public function networkPrefix(): string;

    /**
     * @param array<int|string|null> $params
     * @return list<array<string,mixed>>
     */
    public function select(string $sql, array $params = []): array;

    /**
     * @param array<int|string|null> $params
     * @return array<string,mixed>|null
     */
    public function first(string $sql, array $params = []): ?array;

    /**
     * @param array<int|string|null> $params
     */
    public function scalar(string $sql, array $params = []): mixed;

    /**
     * Run a statement and return affected rows.
     *
     * @param array<int|string|null> $params
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Insert a row, returning the auto-increment id (0 when the table has none).
     *
     * @param array<string,mixed> $data
     */
    public function insert(string $table, array $data): int;

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public function update(string $table, array $data, array $where): int;

    /**
     * @param array<string,mixed> $where
     */
    public function delete(string $table, array $where): int;

    /**
     * Run $work inside a transaction and return its result. Nested calls use
     * savepoints so an engine method can compose another one safely.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function transaction(callable $work): mixed;
}
