<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A BK a slot got out of (story 43.10): the closed SlotBlockEpisode, kept when the block ended with a notification
 * (a real block left, story 40.1), so a recap can tell which item got the player out. The episode itself is removed
 * at the same moment. Starts with this story: older sessions have none.
 */
#[ORM\Entity]
#[ORM\Table(name: 'session_slot_block_release')]
#[ORM\Index(name: 'idx_slot_block_release_session', columns: ['session_id'])]
final class SlotBlockRelease
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $id,

        #[ORM\Column(name: 'session_id', type: Types::STRING, length: 64)]
        private string $sessionId,

        #[ORM\Column(name: 'slot_name', type: Types::STRING, length: 64)]
        private string $slotName,

        #[ORM\Column(name: 'blocked_since', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $blockedSince,

        #[ORM\Column(name: 'released_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $releasedAt,
    ) {
    }

    public static function of(SlotBlockEpisode $episode, string $id, \DateTimeImmutable $now): self
    {
        return new self($id, $episode->getSessionId(), $episode->getSlotName(), $episode->getBlockedSince(), $now);
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getSlotName(): string
    {
        return $this->slotName;
    }

    public function getBlockedSince(): \DateTimeImmutable
    {
        return $this->blockedSince;
    }

    public function getReleasedAt(): \DateTimeImmutable
    {
        return $this->releasedAt;
    }
}
