<?php

declare(strict_types=1);

namespace App\Community\Application\Command;

enum RegisterPushSubscriptionOutcome
{
    case Registered;

    /** Not a browser push subscription: insecure or missing endpoint, missing keys, unknown encoding. */
    case Invalid;

    /** The site has no VAPID keys: pushes are off. */
    case Unavailable;
}
