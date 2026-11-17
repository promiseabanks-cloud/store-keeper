<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Support;

use StoreKeeper\Commerce\Notifications\Channel;
use StoreKeeper\Commerce\Notifications\ChannelFailed;

/** Records delivered messages instead of calling a provider. */
final class InMemoryChannel implements Channel
{
    /** @var list<array{to:string,subject:string,body:string}> */
    public array $sent = [];

    /** Fail this many sends before accepting one, to exercise retries. */
    public int $failuresRemaining = 0;

    public function send(string $recipient, string $subject, string $body): ?string
    {
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;

            throw new ChannelFailed('The test channel refused the message.');
        }

        $this->sent[] = ['to' => $recipient, 'subject' => $subject, 'body' => $body];

        return 'test-' . count($this->sent);
    }
}
