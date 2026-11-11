<?php

declare(strict_types=1);

namespace App\Streaming\Application\Service;

use App\Streaming\Domain\Repository\LocalStreamRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Story 47.6: the streams of an event on the LAN media server, as the régie agent last reported them. A stream is
 * live only while the agent keeps reporting it (30 s at most between two reports). Read by the player's card (47.7),
 * the régie screen (47.8) and the admin (47.9).
 *
 * @phpstan-type LocalStreamView array{registrationId: string, path: string, live: bool, onlineSince: string|null, lastSeenAt: string}
 * @phpstan-type LocalStreamsView array{agentReportedAt: string|null, streams: list<LocalStreamView>}
 */
final readonly class LocalStreamsState
{
    public function __construct(
        private LocalStreamRepositoryInterface $streams,
        private ClockInterface $clock,
    ) {
    }

    /** @return LocalStreamsView */
    public function forEvent(string $eventId): array
    {
        $now = $this->clock->now();
        $views = [];
        foreach ($this->streams->streamsOf($eventId) as $stream) {
            $live = $stream->isLiveAt($now);
            $views[] = [
                'registrationId' => $stream->getRegistrationId(),
                'path' => $stream->getPath(),
                'live' => $live,
                'onlineSince' => $live ? $stream->getOnlineSince()?->format(\DateTimeInterface::ATOM) : null,
                'lastSeenAt' => $stream->getLastSeenAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return [
            'agentReportedAt' => $this->streams->serverOf($eventId)?->getReportedAt()->format(\DateTimeInterface::ATOM),
            'streams' => $views,
        ];
    }

    /** Story 47.7: whether the régie receives this registrant's stream now. */
    public function isLive(string $eventId, string $registrationId): bool
    {
        $now = $this->clock->now();
        foreach ($this->streams->streamsOf($eventId) as $stream) {
            if ($stream->getRegistrationId() === $registrationId) {
                return $stream->isLiveAt($now);
            }
        }

        return false;
    }
}
