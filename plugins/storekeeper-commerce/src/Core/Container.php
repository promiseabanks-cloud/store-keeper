<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core;

use Closure;
use RuntimeException;

/**
 * Minimal constructor-injection container (§7.3). Factories are lazy;
 * singletons are built once.
 */
final class Container
{
    /** @var array<string,Closure(self):mixed> */
    private array $factories = [];

    /** @var array<string,mixed> */
    private array $instances = [];

    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function singleton(string $id, Closure $factory): void
    {
        $this->factories[$id] = function (self $container) use ($id, $factory): mixed {
            $this->instances[$id] ??= $factory($container);

            return $this->instances[$id];
        };
    }

    /** Register an already-built instance. */
    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
        $this->factories[$id] = static fn (): mixed => $value;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }

    public function get(string $id): mixed
    {
        if (!isset($this->factories[$id])) {
            if (str_contains($id, '\\') && class_exists($id)) {
                return new $id();
            }

            throw new RuntimeException(sprintf('Nothing registered in the container for "%s".', $id));
        }

        return ($this->factories[$id])($this);
    }
}
