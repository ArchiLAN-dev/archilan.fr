<?php

declare(strict_types=1);

namespace App\Shared\Application\Message;

/**
 * Story 34.10: drop the cache of the public pages showing what was just written. Dispatched after the write is
 * committed, asynchronous, so a slow or absent frontend never holds an admin's save back.
 */
final readonly class RevalidatePublicPagesJob
{
    /**
     * @param list<string> $tags
     */
    public function __construct(public array $tags)
    {
    }
}
