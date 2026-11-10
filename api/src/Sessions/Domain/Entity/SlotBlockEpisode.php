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
 *
 * Story 52.4: a server restart suspends the episode instead of ending it. When the slot is blocked again with no
 * item received in between, it is the same block: it resumes with its first start, so a restart neither resets the
 * Passeur's decreasing price nor counts as a way out.
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

        /** Items the slot had received when last seen blocked; null when the bridge did not say. */
        #[ORM\Column(name: 'items_received', type: Types::INTEGER, nullable: true)]
        private ?int $itemsReceived = null,

        /** Set while a server restart left the block unconfirmed. */
        #[ORM\Column(name: 'suspended_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $suspendedAt = null,

        /** When the block was last confirmed after a restart. */
        #[ORM\Column(name: 'resumed_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $resumedAt = null,
    ) {
    }

    public static function open(string $sessionId, string $slotIndex, string $slotName, \DateTimeImmutable $now, ?int $itemsReceived = null): self
    {
        return new self($sessionId, $slotIndex, $slotName, $now, $itemsReceived);
    }

    /** Still blocked: remember what the slot holds, to recognise the same block after a restart. */
    public function observe(?int $itemsReceived): void
    {
        if (null !== $itemsReceived) {
            $this->itemsReceived = $itemsReceived;
        }
    }

    public function suspend(\DateTimeImmutable $now): void
    {
        $this->suspendedAt ??= $now;
    }

    public function resume(\DateTimeImmutable $now, int $itemsReceived): void
    {
        $this->suspendedAt = null;
        $this->resumedAt = $now;
        $this->itemsReceived = $itemsReceived;
    }

    /** Blocked again after a restart, but on something new: a fresh block, from now. */
    public function restart(\DateTimeImmutable $now, ?int $itemsReceived): void
    {
        $this->blockedSince = $now;
        $this->itemsReceived = $itemsReceived;
        $this->suspendedAt = null;
        $this->resumedAt = null;
    }

    public function isSuspended(): bool
    {
        return null !== $this->suspendedAt;
    }

    /** Story 40.3: not confirmed since the server's last stop - what was before no longer holds as such. */
    public function predates(?\DateTimeImmutable $stoppedAt): bool
    {
        return null !== $stoppedAt && ($this->resumedAt ?? $this->blockedSince) < $stoppedAt;
    }

    /** The same block after a restart: nothing received since it was last seen. */
    public function continuesWith(?int $itemsReceived): bool
    {
        return null !== $this->itemsReceived && null !== $itemsReceived && $itemsReceived <= $this->itemsReceived;
    }

    public function getItemsReceived(): ?int
    {
        return $this->itemsReceived;
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
