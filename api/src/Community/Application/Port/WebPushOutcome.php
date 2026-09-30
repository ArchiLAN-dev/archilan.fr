<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

enum WebPushOutcome
{
    case Delivered;

    /** The push service no longer knows this device (404 / 410): unsubscribed, uninstalled, expired. */
    case Expired;

    /** Anything else (push service down, keys rejected): logged, the device is kept. */
    case Failed;
}
