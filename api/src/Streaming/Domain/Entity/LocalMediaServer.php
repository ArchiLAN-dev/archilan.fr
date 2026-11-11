<?php

declare(strict_types=1);

namespace App\Streaming\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Story 47.6: when the régie agent of an event last reported, and how many streams it saw live. */
#[ORM\Entity]
#[ORM\Table(name: 'streaming_local_media_server')]
final class LocalMediaServer
{
    #[ORM\Column(name: 'live_count', type: 'integer')]
    private int $liveCount = 0;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'event_id', type: 'string', length: 32)]
        private string $eventId,
        #[ORM\Column(name: 'reported_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $reportedAt,
    ) {
    }

    public function reported(int $liveCount, \DateTimeImmutable $now): void
    {
        $this->liveCount = $liveCount;
        $this->reportedAt = $now;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getReportedAt(): \DateTimeImmutable
    {
        return $this->reportedAt;
    }

    public function getLiveCount(): int
    {
        return $this->liveCount;
    }
}
