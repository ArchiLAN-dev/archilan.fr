<?php

declare(strict_types=1);

namespace App\Events\Application\Query;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Registrations\Application\Query\MyRegistrationQuery;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;

/**
 * Story 53.4: the practical information of an event, for its registrants (confirmed or not) and the admins. It may
 * hold the wifi or a door code, so it never travels with the public event.
 */
final readonly class EventPracticalInfoQuery
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private MyRegistrationQuery $myRegistrationQuery,
    ) {
    }

    /**
     * @return array{markdown: string|null, updatedAt: string|null}
     */
    public function forViewer(string $eventId, string $userId, bool $isAdmin): array
    {
        $event = $this->eventRepository->findById($eventId);
        if (!$event instanceof Event || (!$isAdmin && !$event->isVisiblePublicly())) {
            throw new NotFoundException("Cet événement n'existe pas.", 'event_not_found');
        }

        if (!$isAdmin && null === $this->myRegistrationQuery->findActiveByEventAndUser($eventId, $userId)) {
            throw new ForbiddenException('Les infos pratiques sont réservées aux inscrits.', 'not_registered');
        }

        return [
            'markdown' => $event->getPracticalInfo(),
            'updatedAt' => $event->getPracticalInfoUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
