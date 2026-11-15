<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The engine never calls time functions directly; tests inject a frozen clock (§7.3).
 */
interface Clock
{
    /** Current UTC time, to millisecond precision. */
    public function now(): DateTimeImmutable;

    /** Current UTC time as a DATETIME(3) string. */
    public function nowString(): string;
}
