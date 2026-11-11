<?php

declare(strict_types=1);

namespace App\Streaming\Application\Service;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Streaming\Application\Support\MediaPublishCredentials;

/** Story 47.6: what an admin pastes in `ops/lan-media/.env` for the régie agent of an event. */
final readonly class MediaAgentAccess
{
    public function __construct(
        private EventRepositoryInterface $events,
        private MediaPublishCredentials $credentials,
    ) {
    }

    /**
     * @return array{eventId: string, secret: string}
     *
     * @throws NotFoundException when the event is unknown
     */
    public function forAdmin(string $eventId): array
    {
        if (!$this->events->findById($eventId) instanceof Event) {
            throw new NotFoundException('Événement introuvable.', 'event_not_found');
        }

        return ['eventId' => $eventId, 'secret' => $this->credentials->agentSecretFor($eventId)];
    }
}
