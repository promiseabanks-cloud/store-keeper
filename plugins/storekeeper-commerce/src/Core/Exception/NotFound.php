<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Exception;

final class NotFound extends StoreKeeperException
{
    public function __construct(private readonly string $entity, string|int $identifier)
    {
        parent::__construct(sprintf('%s "%s" was not found.', ucfirst($entity), (string) $identifier));
    }

    public function errorCode(): string
    {
        return 'not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }

    public function context(): array
    {
        return ['entity' => $this->entity];
    }
}
