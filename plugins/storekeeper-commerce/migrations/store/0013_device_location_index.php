<?php

declare(strict_types=1);

/**
 * §15.2, D-042: the storefront buffer asks, for each sellable location, whether
 * it has an active device that has gone quiet. That lookup filters on
 * `location_id` and `status`, so index the pair on `devices`.
 */
return static function (string $prefix): array {
    return [
        "ALTER TABLE {p}devices ADD INDEX location_status (location_id, status)",
    ];
};
