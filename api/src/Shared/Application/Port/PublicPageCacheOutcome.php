<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * Story 34.10: what the frontend answered when asked to drop the cache of public pages.
 */
enum PublicPageCacheOutcome: string
{
    case Revalidated = 'revalidated';
    /** FRONTEND_INTERNAL_URL or REVALIDATE_SECRET is empty: nothing was asked (local dev, CI). */
    case Disabled = 'disabled';
    /** The frontend refused the request (wrong secret, invalid tags): a configuration fault, not worth a retry. */
    case Rejected = 'rejected';
    /** The frontend did not answer or failed: worth a retry. */
    case Unavailable = 'unavailable';
}
