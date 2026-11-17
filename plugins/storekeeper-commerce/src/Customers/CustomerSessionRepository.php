<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Customers;

use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Storefront sessions (§8.2 `customer_sessions`, §7.4).
 *
 * The cookie holds 32 random bytes; the row holds only their SHA-256, so a
 * database dump cannot be replayed as a signed-in shopper.
 */
final class CustomerSessionRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'customer_sessions', $data);
    }

    /** @return array<string,mixed>|null */
    public function byTokenHash(string $tokenHash): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %scustomer_sessions WHERE token_hash = ? LIMIT 1', $this->db->prefix()),
            [$tokenHash]
        );
    }

    public function refresh(int $sessionId, string $expiresAt): void
    {
        $this->db->update($this->db->prefix() . 'customer_sessions', ['expires_at' => $expiresAt], ['id' => $sessionId]);
    }

    public function delete(int $sessionId): void
    {
        $this->db->delete($this->db->prefix() . 'customer_sessions', ['id' => $sessionId]);
    }

    public function deleteForCustomer(int $customerId): void
    {
        $this->db->delete($this->db->prefix() . 'customer_sessions', ['customer_id' => $customerId]);
    }
}
