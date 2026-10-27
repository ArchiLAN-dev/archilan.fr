<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A stretch of time a game client held a slot without a break (story 32.26): the play time is their sum.
 *
 * The bridge's heartbeat (every 30 s) names the slots held right now; each one extends its latest span, or
 * opens a new one when the last sighting is too old. Nothing ever has to close a span: when the heartbeats
 * stop naming a slot (client closed, server down, bridge gone), the span simply stops growing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'session_slot_play_span')]
#[ORM\Index(name: 'idx_slot_play_span_session_slot', columns: ['session_id', 'slot_name'])]
final class SlotPlaySpan
{
    /** Three heartbeats: a single lost request does not cut the span. */
    public const int MAX_GAP_SECONDS = 90;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $id,

        #[ORM\Column(name: 'session_id', type: Types::STRING, length: 64)]
        private string $sessionId,

        /** The slot's registered Archipelago name, as `session_slot.slot_name`. */
        #[ORM\Column(name: 'slot_name', type: Types::STRING, length: 64)]
        private string $slotName,

        #[ORM\Column(name: 'started_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $startedAt,

        #[ORM\Column(name: 'last_seen_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $lastSeenAt,
    ) {
    }

    public static function open(string $id, string $sessionId, string $slotName, \DateTimeImmutable $now): self
    {
        return new self($id, $sessionId, $slotName, $now, $now);
    }

    /** Whether a sighting at `$now` still belongs to this span. */
    public function continuesAt(\DateTimeImmutable $now): bool
    {
        return $now->getTimestamp() - $this->lastSeenAt->getTimestamp() <= self::MAX_GAP_SECONDS;
    }

    public function extendTo(\DateTimeImmutable $now): void
    {
        if ($now > $this->lastSeenAt) {
            $this->lastSeenAt = $now;
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getSlotName(): string
    {
        return $this->slotName;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function durationSeconds(): int
    {
        return max(0, $this->lastSeenAt->getTimestamp() - $this->startedAt->getTimestamp());
    }
}
