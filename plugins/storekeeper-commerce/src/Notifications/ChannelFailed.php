<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Notifications;

use RuntimeException;

/** A channel refused or could not deliver one message. */
final class ChannelFailed extends RuntimeException
{
}
