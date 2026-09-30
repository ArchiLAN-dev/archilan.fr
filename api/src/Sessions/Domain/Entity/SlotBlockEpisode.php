<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A slot stuck in BK (story 40.1): since when, for one slot of one session. The players push holds
 * no history, so the start of a block has to be kept somewhere to tell a real one from the few
 * seconds a fresh item takes to be recomputed. The row lives as long as the block and is removed
 * when it ends - which is also what makes a block notify once at most.
 */
#[ORM\Entity]
#[ORM\Table(name: 'session_slot_block')]
final class SlotBlockEpisode
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'session_id', type: Types::STRING, length: 64)]
        private string $sessionId,

        /** The slot's key in the players push (its Archipelago slot number). */
        #[ORM\Id]
        #[ORM\Column(name: 'slot_index', type: Types::STRING, length: 16)]
        private string $slotIndex,

        #[ORM\Column(name: 'slot_name', type: Types::STRING, length: 64)]
        private string $slotName,

        #[ORM\Column(name: 'blocked_since', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $blockedSince,
    ) {
    }

    public static function open(string $sessionId, string $slotIndex, string $slotName, \DateTimeImmutable $now): self
    {
        return new self($sessionId, $slotIndex, $slotName, $now);
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getSlotIndex(): string
    {
        return $this->slotIndex;
    }

    public function getSlotName(): string
    {
        return $this->slotName;
    }

    public function getBlockedSince(): \DateTimeImmutable
    {
        return $this->blockedSince;
    }

    public function lastedAtLeast(int $seconds, \DateTimeImmutable $now): bool
    {
        return $now->getTimestamp() - $this->blockedSince->getTimestamp() >= $seconds;
    }
}
