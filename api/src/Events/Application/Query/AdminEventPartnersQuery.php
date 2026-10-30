<?php

declare(strict_types=1);

namespace App\Events\Application\Query;

use App\Events\Domain\Repository\EventRepositoryInterface;

/** Story 45.3: the partners chosen for an event, for its admin page. */
final readonly class AdminEventPartnersQuery
{
    public function __construct(private EventRepositoryInterface $eventRepository)
    {
    }

    /**
     * @return list<array{partnerId: string, mention: string|null}>|null null when the event does not exist
     */
    public function forEvent(string $eventId): ?array
    {
        return $this->eventRepository->findById($eventId)?->getPartners();
    }
}
