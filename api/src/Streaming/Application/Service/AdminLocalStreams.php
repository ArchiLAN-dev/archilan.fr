<?php

declare(strict_types=1);

namespace App\Streaming\Application\Service;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Streaming\Domain\Repository\LocalStreamRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Story 47.9: the local broadcasting of an event, as the admin follows it on the day: is the régie agent running,
 * who is on air, who was cut and when. The durations come from the API clock, so the screens need none.
 *
 * @phpstan-type AdminLocalStream array{registrationId: string, live: bool, cutMinutesAgo: int|null}
 * @phpstan-type AdminLocalStreamsView array{mediaServerHost: string|null, agentSeenSecondsAgo: int|null, liveCount: int, streams: list<AdminLocalStream>}
 */
final readonly class AdminLocalStreams
{
    public function __construct(
        private EventRepositoryInterface $events,
        private LocalStreamRepositoryInterface $streams,
        private ClockInterface $clock,
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

        $now = $this->clock->now();
        $views = [];
        $liveCount = 0;
        foreach ($this->streams->streamsOf($eventId) as $stream) {
            $live = $stream->isLiveAt($now);
            $liveCount += $live ? 1 : 0;
            $views[] = [
                'registrationId' => $stream->getRegistrationId(),
                'live' => $live,
                'cutMinutesAgo' => $live ? null : intdiv(max(0, $now->getTimestamp() - $stream->getLastSeenAt()->getTimestamp()), 60),
            ];
        }
        $reportedAt = $this->streams->serverOf($eventId)?->getReportedAt();

        return [
            'mediaServerHost' => $event->getMediaServerHost(),
            'agentSeenSecondsAgo' => null === $reportedAt ? null : max(0, $now->getTimestamp() - $reportedAt->getTimestamp()),
            'liveCount' => $liveCount,
            'streams' => $views,
        ];
    }
}
