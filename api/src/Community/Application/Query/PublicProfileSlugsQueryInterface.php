<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * Slugs of the profiles whose audience is `public` (story 34.8): the exact set an anonymous
 * crawler can see, which is what the frontend sitemap enumerates as /joueurs/{slug}. Deleted and
 * banned accounts are excluded; `updatedAt` is the profile row's real timestamp (sitemap
 * lastModified must never be fabricated).
 */
interface PublicProfileSlugsQueryInterface
{
    /**
     * @return list<array{slug: string, updatedAt: string}>
     */
    public function all(): array;
}
