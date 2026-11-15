<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Idempotency;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use Throwable;

/**
 * Makes every write endpoint safe to retry (§18, D-009).
 *
 * Same key + same body  -> the stored response is returned, work is not repeated.
 * Same key + other body  -> DuplicateRequest (HTTP 409).
 */
final class IdempotencyService
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string,mixed> $request canonical request payload
     * @param callable():array<string,mixed> $work
     */
    public function run(
        string $scope,
        string $actorKey,
        string $idempotencyKey,
        array $request,
        callable $work,
        int $successStatus = 200,
    ): IdempotencyResult {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            throw new ValidationFailed('Idempotency-Key must be 1–64 characters.', ['idempotency_key' => 'required']);
        }

        if ($actorKey === '') {
            throw new ValidationFailed('An actor is required for an idempotent write.');
        }

        $hash = self::hash($request);
        $table = $this->db->prefix() . 'idempotency_keys';

        return $this->db->transaction(function () use ($scope, $actorKey, $idempotencyKey, $hash, $work, $successStatus, $table): IdempotencyResult {
            $existing = $this->db->first(
                sprintf(
                    'SELECT request_hash, status_code, response FROM %s WHERE scope = ? AND actor_key = ? AND idem_key = ? FOR UPDATE',
                    $table
                ),
                [$scope, $actorKey, $idempotencyKey]
            );

            if ($existing !== null) {
                if (!hash_equals((string) $existing['request_hash'], $hash)) {
                    throw new DuplicateRequest('This Idempotency-Key was already used with a different request body.');
                }

                /** @var array<string,mixed> $stored */
                $stored = json_decode((string) $existing['response'], true, 512, JSON_THROW_ON_ERROR);

                return new IdempotencyResult((int) $existing['status_code'], $stored, true);
            }

            $response = $work();

            $this->db->insert($table, [
                'idem_key' => $idempotencyKey,
                'scope' => $scope,
                'actor_key' => $actorKey,
                'request_hash' => $hash,
                'status_code' => $successStatus,
                'response' => json_encode($response, JSON_THROW_ON_ERROR),
                'created_at' => $this->clock->nowString(),
            ]);

            return new IdempotencyResult($successStatus, $response, false);
        });
    }

    /** Purge keys older than the retention window (default 7 days, §8.5). */
    public function purgeOlderThan(int $days = 7): int
    {
        $cutoff = gmdate('Y-m-d H:i:s.v', time() - ($days * 86400));

        return $this->db->execute(
            sprintf('DELETE FROM %s WHERE created_at < ?', $this->db->prefix() . 'idempotency_keys'),
            [$cutoff]
        );
    }

    /** @param array<string,mixed> $request */
    public static function hash(array $request): string
    {
        return hash('sha256', json_encode(self::canonicalise($request), JSON_THROW_ON_ERROR));
    }

    /**
     * Key order must not change the hash of an otherwise identical request.
     *
     * @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    private static function canonicalise(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalise($item);
            }
        }

        return $value;
    }
}
