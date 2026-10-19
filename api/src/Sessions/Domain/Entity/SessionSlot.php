<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
final class SessionSlot
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,

        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $sessionId,

        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $registrationId,

        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $gameId,

        #[ORM\Column(type: Types::STRING, length: 16)]
        private string $slotName,

        #[ORM\Column(type: Types::INTEGER)]
        private int $slotOrder,

        #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
        private ?string $slotId = null,

        #[ORM\Column(type: Types::INTEGER)]
        private int $checksDone = 0,

        #[ORM\Column(type: Types::INTEGER)]
        private int $itemsReceived = 0,

        #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $goalReachedAt = null,

        #[ORM\Column(name: 'was_released', type: 'boolean', options: ['default' => false])]
        private bool $wasReleased = false,

        /** Last time the bridge reported a new check on this slot (story 30.45); null until the first one. */
        #[ORM\Column(name: 'last_check_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $lastCheckAt = null,

        /**
         * Story 30.49: when the slot released (or forfeited) its remaining items to the others, and when it collected
         * its own back - the items moved from then on were never found by anyone. Null on a slot released before.
         */
        #[ORM\Column(name: 'released_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $releasedAt = null,

        #[ORM\Column(name: 'collected_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $collectedAt = null,
    ) {
    }

    public static function create(
        string $id,
        string $sessionId,
        string $registrationId,
        string $gameId,
        string $slotName,
        int $slotOrder,
        ?string $slotId = null,
    ): self {
        return new self($id, $sessionId, $registrationId, $gameId, $slotName, $slotOrder, $slotId);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getRegistrationId(): string
    {
        return $this->registrationId;
    }

    public function getSlotOrder(): int
    {
        return $this->slotOrder;
    }

    public function getGameId(): string
    {
        return $this->gameId;
    }

    public function getSlotName(): string
    {
        return $this->slotName;
    }

    /** The generator assigned this slot its (deduplicated) Archipelago name. */
    public function assignSlotName(string $slotName): void
    {
        $this->slotName = $slotName;
    }

    public function getSlotId(): ?string
    {
        return $this->slotId;
    }

    public function getChecksDone(): int
    {
        return $this->checksDone;
    }

    public function getItemsReceived(): int
    {
        return $this->itemsReceived;
    }

    public function getGoalReachedAt(): ?\DateTimeImmutable
    {
        return $this->goalReachedAt;
    }

    /**
     * Story 30.45: the bridge reported a new check on this slot. Archipelago knows the slot, not the person: the
     * activity counts for everyone attached to it (owner and co-players).
     */
    public function recordCheckActivity(\DateTimeImmutable $at): void
    {
        $this->lastCheckAt = $at;
    }

    public function getLastCheckAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckAt;
    }

    /** Live progress reported by the bridge. */
    public function recordProgress(int $checksDone, int $itemsReceived): void
    {
        $this->checksDone = $checksDone;
        $this->itemsReceived = $itemsReceived;
    }

    /**
     * The slot reached its goal. Idempotent: the instant is captured once, because the
     * bridge callback may fire more than once (cf. Notification::markRead).
     */
    public function recordGoal(\DateTimeImmutable $goalReachedAt): void
    {
        $this->goalReachedAt ??= $goalReachedAt;
    }

    /**
     * Archive reconciliation (story 9.16): the archived run is authoritative, so unlike
     * recordGoal() this overwrites - and may clear - the goal instant.
     */
    public function syncFromArchive(int $checksDone, int $itemsReceived, ?\DateTimeImmutable $goalReachedAt): void
    {
        $this->checksDone = $checksDone;
        $this->itemsReceived = $itemsReceived;
        $this->goalReachedAt = $goalReachedAt;
    }

    /**
     * Whether an item this slot sends or receives at `$at` was found by nobody (stories 41.4, 41.37): the feed tells
     * a release or a collect apart from a find nowhere, so a slot that released or collected, or had reached its goal
     * (a goal is followed by the release), no longer finds anything.
     */
    public function stoppedFinding(\DateTimeImmutable $at): bool
    {
        return $this->wasReleased || (null !== $this->goalReachedAt && $this->goalReachedAt <= $at);
    }

    /**
     * Story 41.39: whether this slot had released or collected by `$at`. On the receiving side that is what turns an
     * item into one found by nobody (a collect pulls the slot's items out of the others' worlds); a receiver that only
     * reached its goal still receives items the others actually found.
     */
    public function handedOverBy(\DateTimeImmutable $at): bool
    {
        return $this->wasReleased
            || (null !== $this->releasedAt && $this->releasedAt <= $at)
            || (null !== $this->collectedAt && $this->collectedAt <= $at);
    }

    public function markAsReleased(): void
    {
        if (null !== $this->goalReachedAt) {
            return;
        }

        $this->wasReleased = true;
    }

    /**
     * Story 30.49: the first release (or forfeit) and the first collect of the slot, whatever its goal: a slot that
     * finished still floods the others when it releases.
     */
    public function recordHandOver(bool $collect, \DateTimeImmutable $at): void
    {
        if ($collect) {
            $this->collectedAt ??= $at;

            return;
        }
        $this->releasedAt ??= $at;
    }

    public function getReleasedAt(): ?\DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function getCollectedAt(): ?\DateTimeImmutable
    {
        return $this->collectedAt;
    }

    public function isWasReleased(): bool
    {
        return $this->wasReleased;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'sessionId' => $this->sessionId,
            'registrationId' => $this->registrationId,
            'gameId' => $this->gameId,
            'slotName' => $this->slotName,
            'slotOrder' => $this->slotOrder,
            'slotId' => $this->slotId,
            'checksDone' => $this->checksDone,
            'itemsReceived' => $this->itemsReceived,
            'goalReachedAt' => $this->goalReachedAt?->format(\DateTimeInterface::ATOM),
            'wasReleased' => $this->wasReleased,
        ];
    }
}
