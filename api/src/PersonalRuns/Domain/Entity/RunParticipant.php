<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
final class RunParticipant
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'personal_run_id', type: 'string', length: 32)]
        private string $runId,
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'joined_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $joinedAt,
        /**
         * Ordered list of game slots chosen by this participant. `preflight` is the solo
         * test-generation verdict of the slot's CURRENT yaml (story 9.42): keyed by yamlSha
         * so an edit invalidates it, advisory only (never blocks a launch).
         *
         * @var list<array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null, preflight?: array{status: string, error: string, checkedAt: string, yamlSha: string}|null}>
         */
        #[ORM\Column(name: 'game_slots', type: Types::JSON)]
        private array $gameSlots = [],
    ) {
    }

    public static function create(string $runId, string $userId, \DateTimeImmutable $now): self
    {
        return new self($runId, $userId, $now);
    }

    public function getRunId(): string
    {
        return $this->runId;
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
     * @return list<array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null, preflight?: array{status: string, error: string, checkedAt: string, yamlSha: string}|null}>
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
        $preflightBySlotId = [];
        foreach ($this->gameSlots as $existing) {
            if (isset($existing['preflight'])) {
                $preflightBySlotId[$existing['slotId']] = $existing['preflight'];
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
            // A kept slot keeps its preflight verdict (story 9.42): the yaml did not change.
            if (isset($preflightBySlotId[$slot['slotId']])) {
                $entry['preflight'] = $preflightBySlotId[$slot['slotId']];
            }

            $orderedSlots[] = $entry;
        }

        $this->gameSlots = $orderedSlots;
    }

    public function submitSlotPlayerYaml(string $slotId, string $playerYaml, string $apworldHash): void
    {
        foreach ($this->gameSlots as &$slot) {
            if ($slot['slotId'] === $slotId) {
                $slot['playerYaml'] = $playerYaml;
                $slot['apworldHash'] = $apworldHash;
                // The verdict was for the previous yaml - a stale badge must not survive.
                unset($slot['preflight']);

                return;
            }
        }

        throw new \DomainException(sprintf('Slot "%s" not found in participant game slots.', $slotId));
    }

    /**
     * Records the solo test-generation verdict for a slot (story 9.42). Returns false when
     * the slot no longer exists (selection changed while the check ran).
     */
    public function recordSlotPreflight(string $slotId, string $status, string $error, string $yamlSha, \DateTimeImmutable $now): bool
    {
        foreach ($this->gameSlots as &$slot) {
            if ($slot['slotId'] === $slotId) {
                $slot['preflight'] = [
                    'status' => $status,
                    'error' => $error,
                    'checkedAt' => $now->format(\DateTimeInterface::ATOM),
                    'yamlSha' => $yamlSha,
                ];

                return true;
            }
        }

        return false;
    }

    /**
     * @return array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null, preflight?: array{status: string, error: string, checkedAt: string, yamlSha: string}|null}|null
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
