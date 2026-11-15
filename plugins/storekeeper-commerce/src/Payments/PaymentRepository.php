<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Payments;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Uuid;

/**
 * Gateway payment rows (§8.4 payments, §13.2).
 *
 * The unique key (method, gateway_reference) is what makes a webhook delivered
 * twice harmless: the second delivery finds the same row and changes nothing.
 */
final class PaymentRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /** @param array<string,mixed> $row */
    public function create(array $row): int
    {
        $now = $this->clock->nowString();

        return $this->db->insert($this->db->prefix() . 'payments', [
            'uuid' => Uuid::v4(),
            'order_id' => (int) $row['order_id'],
            'method' => (string) $row['method'],
            'status' => (string) ($row['status'] ?? 'pending'),
            'amount_kobo' => (int) $row['amount_kobo'],
            'gateway_reference' => $row['gateway_reference'] ?? null,
            'gateway_fee_kobo' => $row['gateway_fee_kobo'] ?? null,
            'gateway_payload' => isset($row['gateway_payload'])
                ? json_encode($row['gateway_payload'], JSON_THROW_ON_ERROR)
                : null,
            'sender_name' => null,
            'confirmed_by' => $row['confirmed_by'] ?? null,
            'idempotency_key' => (string) $row['idempotency_key'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %spayments WHERE id = ? LIMIT 1', $this->db->prefix()),
            [$id]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByGatewayReference(string $method, string $reference): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %spayments WHERE method = ? AND gateway_reference = ? LIMIT 1', $this->db->prefix()),
            [$method, $reference]
        );
    }

    /** @param array<string,mixed> $payload */
    public function rememberResponse(int $id, array $payload): void
    {
        $this->db->update(
            $this->db->prefix() . 'payments',
            [
                'gateway_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'updated_at' => $this->clock->nowString(),
            ],
            ['id' => $id]
        );
    }

    /** @param array<string,mixed> $payload */
    public function markSucceeded(int $id, int $amountKobo, int $feeKobo, array $payload): void
    {
        $now = $this->clock->nowString();

        $this->db->update(
            $this->db->prefix() . 'payments',
            [
                'status' => 'succeeded',
                'amount_kobo' => $amountKobo,
                'gateway_fee_kobo' => $feeKobo,
                'gateway_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'confirmed_at' => $now,
                'updated_at' => $now,
            ],
            ['id' => $id]
        );
    }

    /** @param array<string,mixed> $payload */
    public function markFailed(int $id, array $payload): void
    {
        $this->db->update(
            $this->db->prefix() . 'payments',
            [
                'status' => 'failed',
                'gateway_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'updated_at' => $this->clock->nowString(),
            ],
            ['id' => $id]
        );
    }
}
