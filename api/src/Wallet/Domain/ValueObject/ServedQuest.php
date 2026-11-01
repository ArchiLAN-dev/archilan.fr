<?php

declare(strict_types=1);

namespace App\Wallet\Domain\ValueObject;

use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Entity\QuestWeekEntry;

/**
 * A quest as a week serves it (story 41.67): the objectives and reward frozen in its week entry, with the game drawn
 * for it; the title, description and cosmetic stay those of the quest, so a typo fixed shows at once. An entry not
 * frozen reads its quest.
 */
final readonly class ServedQuest
{
    /**
     * @param list<QuestObjective> $objectives
     */
    private function __construct(
        public QuestDefinition $quest,
        public array $objectives,
        public int $reward,
        public ?string $drawnGameId,
    ) {
    }

    public static function of(QuestDefinition $quest, QuestWeekEntry $entry): self
    {
        $objectives = $entry->getObjectives();
        $reward = $entry->getReward();

        return null === $objectives || null === $reward
            ? new self($quest, $quest->getObjectives(), $quest->getReward(), null)
            : new self($quest, $objectives, $reward, $entry->getDrawnGameId());
    }

    public function getId(): string
    {
        return $this->quest->getId();
    }

    public function getTitle(): string
    {
        return $this->quest->getTitle();
    }

    public function getDescription(): string
    {
        return $this->quest->getDescription();
    }

    public function getReward(): int
    {
        return $this->reward;
    }

    /** @return list<QuestObjective> */
    public function getObjectives(): array
    {
        return $this->objectives;
    }

    /** @return array{type: string, key: string}|null */
    public function getCosmetic(): ?array
    {
        return $this->quest->getCosmetic();
    }

    /**
     * @param array<string, int> $counts objective key => count
     */
    public function isAccomplishedWith(array $counts): bool
    {
        return QuestObjective::allReached($this->objectives, $counts);
    }

    /**
     * What these quests count, each objective key once.
     *
     * @param list<self> $served
     *
     * @return list<QuestObjective>
     */
    public static function objectivesOf(array $served): array
    {
        $objectives = [];
        foreach ($served as $quest) {
            foreach ($quest->objectives as $objective) {
                $objectives[$objective->key()] = $objective;
            }
        }

        return array_values($objectives);
    }
}
