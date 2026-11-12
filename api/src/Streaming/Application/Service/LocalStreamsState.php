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
 * @phpstan-type LocalStreamFollowUp array{registrationId: string, live: bool, cutMinutesAgo: int|null}
 * @phpstan-type LocalStreamsFollowUp array{agentSeenSecondsAgo: int|null, liveCount: int, streams: list<LocalStreamFollowUp>}
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

    /**
     * Story 47.9: the same state as the admin follows it on the day, durations computed from the API clock.
     *
     * @return LocalStreamsFollowUp
     */
    public function followUp(string $eventId): array
    {
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
            'agentSeenSecondsAgo' => null === $reportedAt ? null : max(0, $now->getTimestamp() - $reportedAt->getTimestamp()),
            'liveCount' => $liveCount,
            'streams' => $views,
        ];
    }

    /**
     * Story 47.8: the registrations whose stream the régie receives now.
     *
     * @return array<string, true>
     */
    public function liveRegistrations(string $eventId): array
    {
        $now = $this->clock->now();
        $live = [];
        foreach ($this->streams->streamsOf($eventId) as $stream) {
            if ($stream->isLiveAt($now)) {
                $live[$stream->getRegistrationId()] = true;
            }
        }

        return $live;
    }

    /** Story 47.7: whether the régie receives this registrant's stream now. */
    public function isLive(string $eventId, string $registrationId): bool
    {
        return true === $this->streams->streamOf($eventId, $registrationId)?->isLiveAt($this->clock->now());
    }
}
