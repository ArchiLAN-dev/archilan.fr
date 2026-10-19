<?php

declare(strict_types=1);

namespace App\Shared\Application\Support;

use App\Shared\Application\Message\RevalidatePublicPagesJob;
use App\Shared\Application\Port\PublicPageCacheInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Story 34.10: what a write calls once it is saved, so the public pages showing it are rebuilt at the next visit.
 * It only queues the job: the call to the frontend happens on the async worker, after the commit.
 */
final readonly class PublicPagesRevalidation
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    /** An event was created or changed: its page, the events list and the home page. */
    public function eventChanged(string $eventId): void
    {
        $this->messageBus->dispatch(new RevalidatePublicPagesJob([PublicPageCacheInterface::EVENTS, 'event:'.$eventId]));
    }

    /** A post was created or changed, under one slug or, when it was renamed, two. */
    public function postChanged(string ...$slugs): void
    {
        $tags = [PublicPageCacheInterface::POSTS];
        foreach (array_unique($slugs) as $slug) {
            if ('' !== $slug) {
                $tags[] = 'post:'.$slug;
            }
        }

        $this->messageBus->dispatch(new RevalidatePublicPagesJob($tags));
    }
}
