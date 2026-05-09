<?php

declare(strict_types=1);

namespace App\Sessions\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'archipelago_session_slots')]
class SessionSlot
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

    public function setSlotName(string $slotName): void
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

    public function setChecksDone(int $checksDone): void
    {
        $this->checksDone = $checksDone;
    }

    public function getItemsReceived(): int
    {
        return $this->itemsReceived;
    }

    public function setItemsReceived(int $itemsReceived): void
    {
        $this->itemsReceived = $itemsReceived;
    }

    public function getGoalReachedAt(): ?\DateTimeImmutable
    {
        return $this->goalReachedAt;
    }

    public function setGoalReachedAt(?\DateTimeImmutable $goalReachedAt): void
    {
        $this->goalReachedAt = $goalReachedAt;
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
        ];
    }
}
