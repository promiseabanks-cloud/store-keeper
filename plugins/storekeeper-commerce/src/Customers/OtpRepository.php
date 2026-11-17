<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Customers;

use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * One-time sign-in codes (§8.2 `otp_codes`).
 *
 * Only a bcrypt hash of the code is stored, so a database read cannot reveal a
 * code that is still valid. The plain code exists only in the sender's message.
 */
final class OtpRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'otp_codes', $data);
    }

    /** @return array<string,mixed>|null the newest code issued for a phone */
    public function latest(string $phoneE164): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sotp_codes WHERE phone_e164 = ? ORDER BY id DESC LIMIT 1', $this->db->prefix()),
            [$phoneE164]
        );
    }

    public function countForPhoneSince(string $phoneE164, string $since): int
    {
        return (int) $this->db->scalar(
            sprintf('SELECT COUNT(*) FROM %sotp_codes WHERE phone_e164 = ? AND created_at >= ?', $this->db->prefix()),
            [$phoneE164, $since]
        );
    }

    public function countForIpSince(string $ip, string $since): int
    {
        return (int) $this->db->scalar(
            sprintf('SELECT COUNT(*) FROM %sotp_codes WHERE ip = ? AND created_at >= ?', $this->db->prefix()),
            [$ip, $since]
        );
    }

    public function incrementAttempts(int $otpId): void
    {
        $this->db->execute(
            sprintf('UPDATE %sotp_codes SET attempts = attempts + 1 WHERE id = ?', $this->db->prefix()),
            [$otpId]
        );
    }

    public function consume(int $otpId, string $at): void
    {
        $this->db->update($this->db->prefix() . 'otp_codes', ['consumed_at' => $at], ['id' => $otpId]);
    }
}
