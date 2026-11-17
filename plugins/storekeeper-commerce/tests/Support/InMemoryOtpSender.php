<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Support;

use StoreKeeper\Commerce\Storefront\Otp\OtpSender;

/**
 * Captures codes instead of sending them, so tests can act like the shopper
 * reading their phone.
 */
final class InMemoryOtpSender implements OtpSender
{
    /** @var list<array{phone:string,code:string,purpose:string,email:?string}> */
    public array $sent = [];

    public function send(string $phoneE164, string $code, string $purpose, ?string $email = null): string
    {
        $this->sent[] = ['phone' => $phoneE164, 'code' => $code, 'purpose' => $purpose, 'email' => $email];

        return $email === null || $email === '' ? 'log' : 'email';
    }

    public function lastEmail(string $phoneE164): ?string
    {
        $email = null;

        foreach ($this->sent as $message) {
            if ($message['phone'] === $phoneE164) {
                $email = $message['email'];
            }
        }

        return $email;
    }

    public function lastCode(string $phoneE164): ?string
    {
        $code = null;

        foreach ($this->sent as $message) {
            if ($message['phone'] === $phoneE164) {
                $code = $message['code'];
            }
        }

        return $code;
    }

    public function count(): int
    {
        return count($this->sent);
    }
}
