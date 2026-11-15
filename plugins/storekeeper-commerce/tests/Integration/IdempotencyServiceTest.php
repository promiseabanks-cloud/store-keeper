<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Idempotency\DuplicateRequest;
use StoreKeeper\Commerce\Core\Idempotency\IdempotencyService;
use StoreKeeper\Commerce\Tests\Support\TestStore;

final class IdempotencyServiceTest extends TestCase
{
    private TestStore $store;

    private IdempotencyService $idempotency;

    private int $calls = 0;

    protected function setUp(): void
    {
        $this->store = TestStore::create();
        $this->idempotency = new IdempotencyService($this->store->db, $this->store->clock);
        $this->calls = 0;
    }

    public function testRetryingTheSameRequestReturnsTheStoredResponseWithoutRerunning(): void
    {
        $work = function (): array {
            $this->calls++;

            return ['order_id' => 42];
        };

        $first = $this->idempotency->run('orders.create', 'staff:1', 'key-1', ['qty' => 2], $work, 201);
        $second = $this->idempotency->run('orders.create', 'staff:1', 'key-1', ['qty' => 2], $work, 201);

        self::assertFalse($first->replayed);
        self::assertSame(201, $first->statusCode);
        self::assertSame(['order_id' => 42], $first->response);

        self::assertTrue($second->replayed);
        self::assertSame(['order_id' => 42], $second->response);
        self::assertSame(1, $this->calls);
    }

    public function testKeyOrderDoesNotChangeTheRequestHash(): void
    {
        $work = fn (): array => ['ok' => true];

        $first = $this->idempotency->run('scope', 'actor', 'key-order', ['a' => 1, 'b' => ['x' => 1, 'y' => 2]], $work);
        $second = $this->idempotency->run('scope', 'actor', 'key-order', ['b' => ['y' => 2, 'x' => 1], 'a' => 1], $work);

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
    }

    public function testReusingAKeyWithADifferentBodyConflicts(): void
    {
        $work = fn (): array => ['ok' => true];
        $this->idempotency->run('orders.create', 'staff:1', 'key-2', ['qty' => 2], $work);

        $this->expectException(DuplicateRequest::class);

        $this->idempotency->run('orders.create', 'staff:1', 'key-2', ['qty' => 3], $work);
    }

    public function testTheSameKeyIsScopedPerActor(): void
    {
        $work = function (): array {
            $this->calls++;

            return ['ok' => true];
        };

        $this->idempotency->run('orders.create', 'staff:1', 'shared-key', ['qty' => 1], $work);
        $other = $this->idempotency->run('orders.create', 'staff:2', 'shared-key', ['qty' => 1], $work);

        self::assertFalse($other->replayed);
        self::assertSame(2, $this->calls);
    }

    public function testAnEmptyKeyIsRejected(): void
    {
        $this->expectException(\StoreKeeper\Commerce\Core\Exception\ValidationFailed::class);

        $this->idempotency->run('scope', 'actor', '  ', [], fn (): array => []);
    }
}
