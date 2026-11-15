<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Payments\Gateway;

/**
 * The tiny slice of HTTP the gateway adapters need (§13.2).
 *
 * Keeping this behind an interface means adapters can be tested against a fake
 * transport, with no network and no WordPress.
 */
interface HttpClient
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    public function request(string $method, string $url, array $headers, ?string $body = null): array;
}
