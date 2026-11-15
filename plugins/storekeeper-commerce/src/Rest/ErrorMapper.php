<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Rest;

use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;

/**
 * Maps domain exceptions to the stable error shape in §18.
 *
 * Controllers never build error bodies themselves; they call this.
 */
final class ErrorMapper
{
    /**
     * @return array{code:string,message:string,data:array<string,mixed>}
     */
    public static function toArray(\Throwable $error): array
    {
        if ($error instanceof StoreKeeperException) {
            return [
                'code' => $error->errorCode(),
                'message' => $error->getMessage(),
                'data' => [...$error->context(), 'status' => $error->httpStatus()],
            ];
        }

        return [
            'code' => 'internal_error',
            'message' => 'Something went wrong. Please try again.',
            'data' => ['status' => 500],
        ];
    }

    /**
     * WordPress REST error for the same exception.
     */
    public static function toWpError(\Throwable $error): \WP_Error
    {
        $mapped = self::toArray($error);

        return new \WP_Error($mapped['code'], $mapped['message'], $mapped['data']);
    }

    public static function statusFor(\Throwable $error): int
    {
        return $error instanceof StoreKeeperException ? $error->httpStatus() : 500;
    }
}
