<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Entity;

use App\Wallet\Domain\ValueObject\QuestObjective;
use Doctrine\ORM\Mapping as ORM;

/**
 * A weekly quest written by the admins (story 41.15): a title, a reward in gold pelles, and 1 to 5 objectives that
 * must all be reached in the week. In the draw, it may come out any Monday; out of it, it only comes when pinned to
 * a week. Retired, it neither comes out nor pins, and the weeks it was served in stay as they were.
 */
#[ORM\Entity]
#[ORM\Table(name: 'quest_definition')]
final class QuestDefinition
{
    public const int MIN_REWARD = 1;
    public const int MAX_REWARD = 1000;
    public const int MAX_OBJECTIVES = 5;
    public const int MAX_TITLE = 80;
    public const int MAX_DESCRIPTION = 200;
    // Story 41.18: how much more often the quest comes out of a draw than a weight-1 one.
    public const int MIN_WEIGHT = 1;
    public const int MAX_WEIGHT = 5;

    /**
     * @param list<array{metric: string, target: int, scope?: string, scopeId?: string}> $objectives
     */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(type: 'string', length: 80)]
        private string $title,
        #[ORM\Column(type: 'string', length: 200)]
        private string $description,
        #[ORM\Column(type: 'integer')]
        private int $reward,
        #[ORM\Column(type: 'json')]
        private array $objectives,
        #[ORM\Column(name: 'in_draw', type: 'boolean')]
        private bool $inDraw,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'retired_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $retiredAt = null,
        #[ORM\Column(name: 'draw_weight', type: 'integer', options: ['default' => 1])]
        private int $drawWeight = 1,
    ) {
    }

    /**
     * @param list<QuestObjective> $objectives
     */
    public static function write(string $title, string $description, int $reward, array $objectives, bool $inDraw, int $drawWeight, \DateTimeImmutable $now, ?string $id = null): self
    {
        [$title, $description] = self::assertTerms($title, $description, $reward, $objectives, $drawWeight);

        return new self($id ?? bin2hex(random_bytes(16)), $title, $description, $reward, self::stored($objectives), $inDraw, $now, drawWeight: $drawWeight);
    }

    /**
     * @param list<QuestObjective> $objectives
     */
    public function edit(string $title, string $description, int $reward, array $objectives, bool $inDraw, int $drawWeight): void
    {
        [$this->title, $this->description] = self::assertTerms($title, $description, $reward, $objectives, $drawWeight);
        $this->reward = $reward;
        $this->objectives = self::stored($objectives);
        $this->inDraw = $inDraw;
        $this->drawWeight = $drawWeight;
    }

    public function retire(\DateTimeImmutable $now): void
    {
        $this->retiredAt ??= $now;
    }

    public function restore(): void
    {
        $this->retiredAt = null;
    }

    public function isRetired(): bool
    {
        return null !== $this->retiredAt;
    }

    /** May come out of a Monday draw. */
    public function isDrawable(): bool
    {
        return $this->inDraw && !$this->isRetired();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getReward(): int
    {
        return $this->reward;
    }

    /** @return list<QuestObjective> */
    public function getObjectives(): array
    {
        return QuestObjective::listFromArray($this->objectives);
    }

    /**
     * Every objective reached with what the member did over the week.
     *
     * @param array<string, int> $counts objective key => count
     */
    public function isAccomplishedWith(array $counts): bool
    {
        foreach ($this->getObjectives() as $objective) {
            if (!$objective->isReachedBy($counts[$objective->key()] ?? 0)) {
                return false;
            }
        }

        return [] !== $this->getObjectives();
    }

    /**
     * What these quests count, each objective key once (story 41.18: a metric narrowed to a game or an event is
     * its own count).
     *
     * @param list<self> $quests
     *
     * @return list<QuestObjective>
     */
    public static function objectivesOf(array $quests): array
    {
        $objectives = [];
        foreach ($quests as $quest) {
            foreach ($quest->getObjectives() as $objective) {
                $objectives[$objective->key()] = $objective;
            }
        }

        return array_values($objectives);
    }

    public function getDrawWeight(): int
    {
        return $this->drawWeight;
    }

    public function isInDraw(): bool
    {
        return $this->inDraw;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @param list<QuestObjective> $objectives
     *
     * @return array{0: string, 1: string} the trimmed title and description
     */
    private static function assertTerms(string $title, string $description, int $reward, array $objectives, int $drawWeight): array
    {
        $title = trim($title);
        $description = trim($description);
        if ('' === $title || mb_strlen($title) > self::MAX_TITLE || mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new \DomainException('quest_text_invalid');
        }
        if ($reward < self::MIN_REWARD || $reward > self::MAX_REWARD) {
            throw new \DomainException('quest_reward_invalid');
        }
        if ([] === $objectives || \count($objectives) > self::MAX_OBJECTIVES) {
            throw new \DomainException('quest_objectives_invalid');
        }
        // Story 41.18: one type may come back if it aims at different games or events.
        $keys = array_map(static fn (QuestObjective $objective): string => $objective->key(), $objectives);
        if (\count(array_unique($keys)) !== \count($keys)) {
            throw new \DomainException('quest_objectives_duplicated');
        }
        if ($drawWeight < self::MIN_WEIGHT || $drawWeight > self::MAX_WEIGHT) {
            throw new \DomainException('quest_weight_invalid');
        }

        return [$title, $description];
    }

    /**
     * @param list<QuestObjective> $objectives
     *
     * @return list<array{metric: string, target: int}>
     */
    private static function stored(array $objectives): array
    {
        return array_map(static fn (QuestObjective $objective): array => $objective->toArray(), $objectives);
    }
}
