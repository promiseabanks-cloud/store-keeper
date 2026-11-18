<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Pos;

use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Audit\AuditLogger;
use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Dates;
use StoreKeeper\Commerce\Core\Exception\NotFound;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Uuid;
use StoreKeeper\Commerce\Inventory\InventoryRepository;

/**
 * POS device registration, device tokens and cashier PINs (§15.1).
 *
 * A device is tied to one location. Its token is shown once at registration and
 * stored hashed; revoking it stops offline sync immediately. A cashier signs in
 * on the device with a 4–6 digit PIN, so one tablet can serve several people and
 * every sale records who sold it.
 */
final class DeviceService
{
    private const MAX_PIN_ATTEMPTS = 5;

    private const LOCK_MINUTES = 15;

    public function __construct(
        private readonly Connection $db,
        private readonly DeviceRepository $devices,
        private readonly InventoryRepository $locations,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string,mixed> $input name, location_id, app_version
     * @return array{device:array<string,mixed>,token:string}
     */
    public function register(array $input, Actor $actor): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new ValidationFailed('Give this device a name.', ['name' => 'Required.']);
        }

        if (mb_strlen($name) > 100) {
            throw new ValidationFailed('The device name is too long.', ['name' => 'Maximum 100 characters.']);
        }

        $locationId = (int) ($input['location_id'] ?? 0);
        $location = $locationId > 0 ? $this->locations->location($locationId) : null;
        if ($location === null || (int) ($location['is_active'] ?? 0) !== 1) {
            throw new ValidationFailed('Choose the location this device sells from.', [
                'location_id' => 'An active location is required.',
            ]);
        }

        $token = 'skd_' . bin2hex(random_bytes(32));
        $now = $this->clock->nowString();

        $deviceId = $this->devices->create([
            'uuid' => Uuid::v4(),
            'name' => $name,
            'location_id' => $locationId,
            'token_hash' => hash('sha256', $token),
            'status' => 'active',
            'app_version' => mb_substr(trim((string) ($input['app_version'] ?? '')), 0, 20) ?: null,
            'last_sync_at' => null,
            'last_seen_at' => null,
            'registered_by' => (int) ($actor->id ?? 0),
            'created_at' => $now,
        ]);

        $device = $this->devices->find($deviceId) ?? [];

        $this->audit->log($actor, 'device.registered', 'device', $deviceId, null, [
            'name' => $name,
            'location_id' => $locationId,
        ]);

        return ['device' => $device, 'token' => $token];
    }

    /**
     * Resolve a device from its plain token. Null when the token is unknown or
     * the device was revoked.
     *
     * @return array<string,mixed>|null
     */
    public function authenticate(?string $token): ?array
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }

        $device = $this->devices->findByTokenHash(hash('sha256', $token));
        if ($device === null || ($device['status'] ?? '') !== 'active') {
            return null;
        }

        $this->devices->touchSeen((int) $device['id']);

        return $this->devices->find((int) $device['id']);
    }

    public function revoke(string $uuid, Actor $actor): array
    {
        $device = $this->devices->findByUuid($uuid);
        if ($device === null) {
            throw new NotFound('device', $uuid);
        }

        $this->devices->update((int) $device['id'], ['status' => 'revoked']);
        $this->audit->log($actor, 'device.revoked', 'device', (int) $device['id'], ['status' => $device['status']], ['status' => 'revoked']);

        return $this->devices->find((int) $device['id']) ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->devices->all();
    }

    /**
     * Devices that have not synced for longer than the store allows. The
     * storefront buffer (§15.4) and the "device stale" alert both read this.
     *
     * @return list<array<string,mixed>>
     */
    public function stale(int $minutes): array
    {
        return $this->devices->stale(Dates::addMinutes($this->clock->nowString(), -max(1, $minutes)));
    }

    public function touchSynced(int $deviceId): void
    {
        $this->devices->touchSynced($deviceId);
    }

    public function setPin(int $userId, string $pin, Actor $actor): void
    {
        if (preg_match('/^\d{4,6}$/', $pin) !== 1) {
            throw new ValidationFailed('A cashier PIN is 4 to 6 digits.', ['pin' => '4 to 6 digits.']);
        }

        if ($userId <= 0) {
            throw new ValidationFailed('Choose the staff member this PIN belongs to.', ['user_id' => 'Required.']);
        }

        $hash = password_hash($pin, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('The PIN could not be hashed.');
        }

        $now = $this->clock->nowString();
        $existing = $this->db->first(
            sprintf('SELECT user_id FROM %sstaff_pins WHERE user_id = ?', $this->db->prefix()),
            [$userId]
        );

        if ($existing === null) {
            $this->db->insert($this->db->prefix() . 'staff_pins', [
                'user_id' => $userId,
                'pin_hash' => $hash,
                'failed_attempts' => 0,
                'locked_until' => null,
                'set_by' => $actor->id,
                'updated_at' => $now,
            ]);
        } else {
            $this->db->update($this->db->prefix() . 'staff_pins', [
                'pin_hash' => $hash,
                'failed_attempts' => 0,
                'locked_until' => null,
                'set_by' => $actor->id,
                'updated_at' => $now,
            ], ['user_id' => $userId]);
        }

        $this->audit->log($actor, 'staff.pin_set', 'staff', $userId);
    }

    public function hasPin(int $userId): bool
    {
        return $this->db->scalar(
            sprintf('SELECT COUNT(*) FROM %sstaff_pins WHERE user_id = ?', $this->db->prefix()),
            [$userId]
        ) > 0;
    }

    /**
     * Verify a cashier PIN at sign-in. Five wrong tries lock that PIN for 15
     * minutes; a correct one clears the counter.
     */
    public function verifyPin(int $userId, string $pin): array
    {
        $row = $this->db->first(
            sprintf('SELECT * FROM %sstaff_pins WHERE user_id = ?', $this->db->prefix()),
            [$userId]
        );

        if ($row === null) {
            return ['ok' => false, 'reason' => 'no_pin'];
        }

        $now = $this->clock->nowString();
        if (($row['locked_until'] ?? null) !== null && (string) $row['locked_until'] > $now) {
            return ['ok' => false, 'reason' => 'locked'];
        }

        if (!password_verify($pin, (string) $row['pin_hash'])) {
            $attempts = (int) $row['failed_attempts'] + 1;
            $this->db->update($this->db->prefix() . 'staff_pins', [
                'failed_attempts' => $attempts,
                'locked_until' => $attempts >= self::MAX_PIN_ATTEMPTS
                    ? Dates::addMinutes($now, self::LOCK_MINUTES)
                    : null,
                'updated_at' => $now,
            ], ['user_id' => $userId]);

            return ['ok' => false, 'reason' => $attempts >= self::MAX_PIN_ATTEMPTS ? 'locked' : 'wrong_pin'];
        }

        $this->db->update($this->db->prefix() . 'staff_pins', [
            'failed_attempts' => 0,
            'locked_until' => null,
            'updated_at' => $now,
        ], ['user_id' => $userId]);

        return ['ok' => true, 'reason' => null];
    }

    /** @return list<array<string,mixed>> */
    public function pinHolders(): array
    {
        return $this->db->select(
            sprintf('SELECT user_id, updated_at FROM %sstaff_pins ORDER BY user_id ASC', $this->db->prefix())
        );
    }
}
