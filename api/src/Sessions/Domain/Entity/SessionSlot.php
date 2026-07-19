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

    public function markAsReleased(): void
    {
        if (null !== $this->goalReachedAt) {
            return;
        }

        $this->wasReleased = true;
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
