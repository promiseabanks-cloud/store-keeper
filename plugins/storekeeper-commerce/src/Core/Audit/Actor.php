<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Audit;

use InvalidArgumentException;

/**
 * Who performed an action (§8.5 audit_log.actor_type).
 * Passed explicitly so impersonation is always distinguishable (D-010).
 */
final class Actor
{
    public const STAFF = 'staff';
    public const OWNER_IMPERSONATION = 'owner_impersonation';
    public const SYSTEM = 'system';
    public const DEVICE = 'device';
    public const CUSTOMER = 'customer';

    private function __construct(
        public readonly string $type,
        public readonly ?int $id = null,
        public readonly ?string $ip = null,
    ) {
        if (!in_array($type, [self::STAFF, self::OWNER_IMPERSONATION, self::SYSTEM, self::DEVICE, self::CUSTOMER], true)) {
            throw new InvalidArgumentException(sprintf('Unknown actor type "%s".', $type));
        }
    }

    public static function staff(int $userId, ?string $ip = null): self
    {
        return new self(self::STAFF, $userId, $ip);
    }

    public static function impersonation(int $ownerUserId, ?string $ip = null): self
    {
        return new self(self::OWNER_IMPERSONATION, $ownerUserId, $ip);
    }

    public static function system(): self
    {
        return new self(self::SYSTEM);
    }

    public static function device(int $deviceId): self
    {
        return new self(self::DEVICE, $deviceId);
    }

    public static function customer(int $customerId): self
    {
        return new self(self::CUSTOMER, $customerId);
    }
}
