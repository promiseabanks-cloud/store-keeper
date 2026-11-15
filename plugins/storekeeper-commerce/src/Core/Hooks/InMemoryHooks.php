<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Hooks;

/**
 * Recording implementation for tests: assert which hooks fired and with what.
 */
final class InMemoryHooks implements Hooks
{
    /** @var list<array{name:string,payload:array<string,mixed>}> */
    private array $fired = [];

    public function do(string $name, array $payload = []): void
    {
        $this->fired[] = ['name' => $name, 'payload' => $payload];
    }

    /** @return list<array{name:string,payload:array<string,mixed>}> */
    public function fired(): array
    {
        return $this->fired;
    }

    /** @return list<array<string,mixed>> payloads of every occurrence of $name */
    public function payloadsFor(string $name): array
    {
        $payloads = [];
        foreach ($this->fired as $event) {
            if ($event['name'] === $name) {
                $payloads[] = $event['payload'];
            }
        }

        return $payloads;
    }

    public function hasFired(string $name): bool
    {
        return $this->payloadsFor($name) !== [];
    }

    public function reset(): void
    {
        $this->fired = [];
    }
}
