<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * Story 34.10: the cache of the frontend's public pages (Next.js ISR). Asking it to revalidate a tag makes the
 * next visit of every page that reads tagged data fetch fresh data, instead of waiting out its five minutes.
 */
interface PublicPageCacheInterface
{
    /** Tags the frontend gives its reads: `events`, `event:<id>`, `posts`, `post:<slug>`, `partners`. */
    public const string EVENTS = 'events';
    public const string POSTS = 'posts';
    public const string PARTNERS = 'partners';

    /**
     * @param list<string> $tags
     */
    public function invalidate(array $tags): PublicPageCacheOutcome;
}
