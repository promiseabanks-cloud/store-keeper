<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core;

use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements Clock
{
    public function __construct(private readonly DateTimeZone $zone = new DateTimeZone('UTC'))
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->zone);
    }

    public function nowString(): string
    {
        return self::format($this->now());
    }

    public static function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
