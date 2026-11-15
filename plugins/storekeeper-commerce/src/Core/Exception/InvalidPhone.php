<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Exception;

final class InvalidPhone extends ValidationFailed
{
    public function errorCode(): string
    {
        return 'invalid_phone';
    }

    public function context(): array
    {
        return ['field' => 'phone'];
    }
}
