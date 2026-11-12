<?php

declare(strict_types=1);

namespace App\Streaming\Application\Service;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Streaming\Application\Support\MediaPublishCredentials;

/**
 * Story 47.9: the local broadcasting of an event, as the admin follows it on the day: is the régie agent running,
 * who is on air, who was cut and when (read from LocalStreamsState).
 *
 * v0.66.1: with a media server, the régie token goes with it: the links of the régie screen carry it.
 *
 * @phpstan-import-type LocalStreamFollowUp from LocalStreamsState
 *
 * @phpstan-type AdminLocalStreamsView array{mediaServerHost: string|null, regieToken: string|null, agentSeenSecondsAgo: int|null, liveCount: int, streams: list<LocalStreamFollowUp>}
 */
final readonly class AdminLocalStreams
{
    public function __construct(
        private EventRepositoryInterface $events,
        private LocalStreamsState $state,
        private MediaPublishCredentials $credentials,
    ) {
    }

    /**
     * @return AdminLocalStreamsView
     *
     * @throws NotFoundException when the event is unknown
     */
    public function forEvent(string $eventId): array
    {
        $event = $this->events->findById($eventId);
        if (!$event instanceof Event) {
            throw new NotFoundException('Événement introuvable.', 'event_not_found');
        }
        $host = $event->getMediaServerHost();

        return [
            'mediaServerHost' => $host,
            'regieToken' => null === $host ? null : $this->credentials->readTokenFor($eventId),
            ...$this->state->followUp($eventId),
        ];
    }
}
