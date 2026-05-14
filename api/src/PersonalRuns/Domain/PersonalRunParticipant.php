<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'personal_run_participants')]
final class PersonalRunParticipant
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'personal_run_id', type: 'string', length: 32)]
        private string $personalRunId,
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'joined_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $joinedAt,
        /**
         * Ordered list of game slots chosen by this participant.
         *
         * @var list<array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null}>
         */
        #[ORM\Column(name: 'game_slots', type: Types::JSON)]
        private array $gameSlots = [],
    ) {
    }

    public static function create(string $personalRunId, string $userId, \DateTimeImmutable $now): self
    {
        return new self($personalRunId, $userId, $now);
    }

    public function getPersonalRunId(): string
    {
        return $this->personalRunId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    /**
     * @return list<array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null}>
     */
    public function getGameSlots(): array
    {
        return $this->gameSlots;
    }

    public function hasSlots(): bool
    {
        return [] !== $this->gameSlots;
    }

    /**
     * @param list<array{slotId: string, gameId: string, playerYaml?: string|null, apworldHash?: string|null}> $slots
     */
    public function replaceSlots(array $slots): void
    {
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

            $orderedSlots[] = $entry;
        }

        $this->gameSlots = $orderedSlots;
    }

    public function setSlotPlayerYaml(string $slotId, string $playerYaml, string $apworldHash): void
    {
        foreach ($this->gameSlots as &$slot) {
            if ($slot['slotId'] === $slotId) {
                $slot['playerYaml'] = $playerYaml;
                $slot['apworldHash'] = $apworldHash;

                return;
            }
        }

        throw new \DomainException(sprintf('Slot "%s" not found in participant game slots.', $slotId));
    }

    /**
     * @return array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null}|null
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
}
