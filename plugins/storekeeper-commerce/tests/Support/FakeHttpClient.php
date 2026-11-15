<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Support;

use RuntimeException;
use StoreKeeper\Commerce\Payments\Gateway\HttpClient;

/**
 * A gateway that answers from a script. Records every request so a test can
 * assert what the adapter actually sent.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $requests = [];

    /** @var list<array{status:int,body:string}> */
    private array $queue = [];

    /** @param array<string,mixed>|string $body */
    public function queue(int $status, array|string $body): void
    {
        $this->queue[] = [
            'status' => $status,
            'body' => is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR),
        ];
    }

    public function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new RuntimeException('No queued HTTP response for ' . $method . ' ' . $url);
        }

        return $next;
    }
}
