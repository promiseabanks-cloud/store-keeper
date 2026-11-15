<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Deterministic clock for tests and for replaying a workload.
 */
final class FrozenClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $at = '2026-11-15 09:00:00.000')
    {
        $this->now = new DateTimeImmutable($at, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function nowString(): string
    {
        return SystemClock::format($this->now);
    }

    /** Move the clock forward, e.g. past a reservation hold. */
    public function advance(string $interval): void
    {
        $this->now = $this->now->modify('+' . $interval);
    }
}
