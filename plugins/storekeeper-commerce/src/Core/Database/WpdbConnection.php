<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Database;

use RuntimeException;
use StoreKeeper\Commerce\Core\Brand;
use Throwable;
use wpdb;

/**
 * Production driver. Every statement goes through $wpdb->prepare() (§19.1),
 * and transactions use real InnoDB semantics required by the ledger (§9.3).
 */
final class WpdbConnection implements Connection
{
    private int $depth = 0;

    public function __construct(private readonly wpdb $db)
    {
    }

    public function prefix(): string
    {
        return $this->db->prefix . Brand::TABLE_PREFIX;
    }

    public function networkPrefix(): string
    {
        return $this->db->base_prefix . Brand::TABLE_PREFIX;
    }

    public function select(string $sql, array $params = []): array
    {
        $rows = $this->db->get_results($this->prepare($sql, $params), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->db->get_row($this->prepare($sql, $params), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        return $this->db->get_var($this->prepare($sql, $params));
    }

    public function execute(string $sql, array $params = []): int
    {
        $result = $this->db->query($this->prepare($sql, $params));
        if ($result === false) {
            throw new RuntimeException('Database statement failed: ' . ($this->db->last_error ?: 'unknown error'));
        }

        return (int) $result;
    }

    public function insert(string $table, array $data): int
    {
        $this->ensureOk($this->db->insert($table, $data), 'insert into ' . $table);

        return (int) $this->db->insert_id;
    }

    public function update(string $table, array $data, array $where): int
    {
        $result = $this->db->update($table, $data, $where);
        $this->ensureOk($result, 'update ' . $table);

        return (int) $result;
    }

    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw new RuntimeException('Refusing to delete every row without a WHERE clause.');
        }

        $result = $this->db->delete($table, $where);
        $this->ensureOk($result, 'delete from ' . $table);

        return (int) $result;
    }

    public function transaction(callable $work): mixed
    {
        $this->depth++;
        if ($this->depth === 1) {
            $this->db->query('START TRANSACTION');
        }

        try {
            $result = $work();
            $this->depth--;
            if ($this->depth === 0) {
                $this->db->query('COMMIT');
            }

            return $result;
        } catch (Throwable $error) {
            $this->depth--;
            if ($this->depth === 0) {
                $this->db->query('ROLLBACK');
            }

            throw $error;
        }
    }

    /**
     * Convert "?" placeholders to $wpdb->prepare() placeholders (§19.1).
     * NULLs are inlined as the literal NULL because prepare() cannot bind them.
     *
     * @param array<int|string|null> $params
     */
    private function prepare(string $sql, array $params): string
    {
        if ($params === []) {
            return $sql;
        }

        $values = array_values($params);
        $bound = [];
        $index = 0;
        $prepared = preg_replace_callback('/\?/', function () use (&$index, $values, &$bound): string {
            $value = $values[$index] ?? null;
            $index++;

            if ($value === null) {
                return 'NULL';
            }

            $bound[] = $value;

            return match (true) {
                is_int($value), is_bool($value) => '%d',
                is_float($value) => '%f',
                default => '%s',
            };
        }, $sql);

        if ($prepared === null) {
            throw new RuntimeException('Could not prepare SQL statement.');
        }

        return $bound === [] ? $prepared : $this->db->prepare($prepared, ...$bound);
    }

    private function ensureOk(int|false $result, string $operation): void
    {
        if ($result === false) {
            throw new RuntimeException(sprintf('Database %s failed: %s', $operation, $this->db->last_error ?: 'unknown error'));
        }
    }
}
