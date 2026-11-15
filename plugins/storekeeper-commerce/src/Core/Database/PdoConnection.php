<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Database;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * PDO driver used by tests, WP-CLI tooling and one-off scripts (D-002).
 * Mirrors the InnoDB semantics the engine relies on: real transactions,
 * row locks and conditional updates.
 */
final class PdoConnection implements Connection
{
    private int $depth = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $storePrefix,
        private readonly string $networkPrefix,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function prefix(): string
    {
        return $this->storePrefix;
    }

    public function networkPrefix(): string
    {
        return $this->networkPrefix;
    }

    public function select(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new RuntimeException('Cannot insert an empty row.');
        }

        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );

        $this->run($sql, array_values($data));

        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        if ($data === []) {
            return 0;
        }

        $set = implode(', ', array_map(static fn (string $column): string => $column . ' = ?', array_keys($data)));
        $condition = implode(' AND ', array_map(static fn (string $column): string => $column . ' = ?', array_keys($where)));
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, $set, $condition);

        return $this->run($sql, [...array_values($data), ...array_values($where)])->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw new RuntimeException('Refusing to delete every row without a WHERE clause.');
        }

        $condition = implode(' AND ', array_map(static fn (string $column): string => $column . ' = ?', array_keys($where)));

        return $this->run(sprintf('DELETE FROM %s WHERE %s', $table, $condition), array_values($where))->rowCount();
    }

    public function transaction(callable $work): mixed
    {
        $savepoint = 'sk_sp_' . $this->depth;
        if ($this->depth === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }

        $this->depth++;

        try {
            $result = $work();
            $this->depth--;

            if ($this->depth === 0) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $error) {
            $this->depth--;

            if ($this->depth === 0) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            }

            throw $error;
        }
    }

    /** @param array<int|string|null> $params */
    private function run(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach (array_values($params) as $index => $value) {
            $position = $index + 1;
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($position, is_bool($value) ? (int) $value : $value, $type);
        }

        $statement->execute();

        return $statement;
    }
}
