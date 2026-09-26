<?php

declare(strict_types=1);

namespace App\Registrations\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_event_registrations_event_user', columns: ['event_id', 'user_id'])]
final class Registration
{
    public const string STATUS_RESERVED = 'reserved';
    public const string STATUS_CANCELLED = 'cancelled';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'event_id', type: 'string', length: 32)]
        private string $eventId,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(type: 'string', length: 20)]
        private string $status,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        /**
         * Ordered list of game slots. Each slot is independent and may reference the same gameId
         * as another slot (e.g. two Hollow Knight instances with different player configs).
         *
         * @var list<array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null, needsReview?: list<string>}>
         */
        #[ORM\Column(name: 'game_slots', type: Types::JSON)]
        private array $gameSlots = [],
        #[ORM\Column(name: 'submitted_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $submittedAt = null,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isReserved(): bool
    {
        return self::STATUS_RESERVED === $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return list<array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null, needsReview?: list<string>}>
     */
    public function getGameSlots(): array
    {
        return $this->gameSlots;
    }

    /**
     * Returns the ordered list of gameIds across all slots (may contain duplicates).
     *
     * @return list<string>
     */
    public function getSelectedGameIds(): array
    {
        return array_map(static fn (array $slot): string => $slot['gameId'], $this->gameSlots);
    }

    /**
     * @param list<array{slotId: string, gameId: string, playerYaml?: string|null, apworldHash?: string|null}> $slots
     */
    public function replaceSlots(array $slots, \DateTimeImmutable $now): void
    {
        if (!$this->isReserved()) {
            throw new \DomainException('Cannot modify slots for an inactive registration.');
        }

        $reviewBySlotId = [];
        foreach ($this->gameSlots as $existing) {
            if (isset($existing['needsReview'])) {
                $reviewBySlotId[$existing['slotId']] = $existing['needsReview'];
            }
        }

        $orderedSlots = [];
        foreach ($slots as $idx => $slot) {
            $entry = [
                'slotId' => $slot['slotId'],
                'gameId' => $slot['gameId'],
                'slotOrder' => $idx + 1,
            ];
            if (array_key_exists('playerYaml', $slot)) {
                $entry['playerYaml'] = $slot['playerYaml'];
            }
            if (array_key_exists('apworldHash', $slot)) {
                $entry['apworldHash'] = $slot['apworldHash'];
            }
            // Reordering the selection is not reviewing the slot (story 38.7).
            if (isset($reviewBySlotId[$slot['slotId']])) {
                $entry['needsReview'] = $reviewBySlotId[$slot['slotId']];
            }

            $orderedSlots[] = $entry;
        }

        $this->gameSlots = $orderedSlots;
        $this->updatedAt = $now;
    }

    public function submitSlotPlayerYaml(string $slotId, string $playerYaml, string $apworldHash, \DateTimeImmutable $now): void
    {
        if (!$this->isReserved()) {
            throw new \DomainException('Cannot set slot YAML for an inactive registration.');
        }

        foreach ($this->gameSlots as &$slot) {
            if ($slot['slotId'] === $slotId) {
                $slot['playerYaml'] = $playerYaml;
                $slot['apworldHash'] = $apworldHash;
                // Saving the slot is reviewing it (story 38.7).
                unset($slot['needsReview']);
                $this->updatedAt = $now;

                return;
            }
        }

        throw new \DomainException(sprintf('Slot "%s" not found in registration.', $slotId));
    }

    /**
     * The slot follows its game to a new apworld before the event is generated (story 38.7). Same
     * contract as the personal-run participant: `$playerYaml` replaces the YAML only when the player never
     * touched it, `$reviewReasons` marks the slot for review until the next save.
     *
     * @param list<string> $reviewReasons
     */
    public function upgradeSlotApworld(string $slotId, string $apworldHash, ?string $playerYaml, array $reviewReasons, \DateTimeImmutable $now): void
    {
        foreach ($this->gameSlots as &$slot) {
            if ($slot['slotId'] !== $slotId) {
                continue;
            }
            $slot['apworldHash'] = $apworldHash;
            if (null !== $playerYaml) {
                $slot['playerYaml'] = $playerYaml;
            }
            if ([] === $reviewReasons) {
                unset($slot['needsReview']);
            } else {
                $slot['needsReview'] = $reviewReasons;
            }
            $this->updatedAt = $now;

            return;
        }

        throw new \DomainException(sprintf('Slot "%s" not found in registration.', $slotId));
    }

    /**
     * @return array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null, needsReview?: list<string>}|null
     */
    public function getSlot(string $slotId): ?array
    {
        foreach ($this->gameSlots as $slot) {
            if ($slot['slotId'] === $slotId) {
                return $slot;
            }
        }

        return null;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function confirm(\DateTimeImmutable $now): void
    {
        if (!$this->isReserved()) {
            throw new \DomainException('Only reserved registrations can be confirmed.');
        }

        $this->submittedAt = $now;
        $this->updatedAt = $now;
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        if (!$this->isReserved()) {
            throw new \DomainException('Only reserved registrations can be cancelled.');
        }

        $this->status = self::STATUS_CANCELLED;
        $this->updatedAt = $now;
    }
}
