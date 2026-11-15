<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Exception;

class ValidationFailed extends StoreKeeperException
{
    /** @var array<string,string> */
    private readonly array $errors;

    /**
     * @param array<string,string> $errors field => message
     */
    public function __construct(string $message = 'The request is invalid.', array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    public function errorCode(): string
    {
        return 'validation_failed';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function context(): array
    {
        return ['errors' => $this->errors];
    }
}
