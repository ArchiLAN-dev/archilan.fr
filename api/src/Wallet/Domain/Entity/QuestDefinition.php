<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Entity;

use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestTier;
use Doctrine\ORM\Mapping as ORM;

/**
 * A weekly quest written by the admins (story 41.15): a title, a reward in gold pelles, and 1 to 5 objectives that
 * must all be reached in the week. In the draw, it may come out any Monday; out of it, it only comes when pinned to
 * a week. Retired, it neither comes out nor pins, and the weeks it was served in stay as they were.
 *
 * Story 41.68: a quest with one objective may have 2 or 3 tiers, each with a higher target and its own reward. The
 * first tier is the quest's target and reward, so the code that knows no tiers stays right.
 *
 * Story 41.69: a collective quest has one objective the whole community counts towards, on a metric that adds up
 * (see QuestMetric::isCollective()), and pays its reward to each member whose share reaches `minContribution`. It
 * never comes out of a draw: an admin pins it, one a week at most, beside the week's quests.
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
    public const int MIN_TIERS = 2;
    public const int MAX_TIERS = 3;

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
        // Story 41.28: the cosmetic the quest unlocks the first time it is done, none by default.
        #[ORM\Column(name: 'cosmetic_type', type: 'string', length: 12, nullable: true)]
        private ?string $cosmeticType = null,
        #[ORM\Column(name: 'cosmetic_key', type: 'string', length: 64, nullable: true)]
        private ?string $cosmeticKey = null,
        /** @var list<array{target: int, reward: int}>|null */
        #[ORM\Column(type: 'json', nullable: true)]
        private ?array $tiers = null,
        #[ORM\Column(type: 'boolean', options: ['default' => false])]
        private bool $collective = false,
        #[ORM\Column(name: 'min_contribution', type: 'integer', nullable: true)]
        private ?int $minContribution = null,
    ) {
    }

    /** Story 41.28: the cosmetic the quest unlocks (both null: none). Checked by the application. */
    public function unlocks(?string $type, ?string $key): void
    {
        $this->cosmeticType = null === $type || null === $key ? null : $type;
        $this->cosmeticKey = null === $type || null === $key ? null : $key;
    }

    /** @return array{type: string, key: string}|null */
    public function getCosmetic(): ?array
    {
        return null === $this->cosmeticType || null === $this->cosmeticKey ? null : ['type' => $this->cosmeticType, 'key' => $this->cosmeticKey];
    }

    /**
     * @param list<QuestObjective> $objectives
     * @param list<QuestTier>      $tiers           none, or 2 to 3 tiers whose first is the objective's target and reward
     * @param int|null             $minContribution story 41.69: the share a member needs to be paid by a collective quest
     */
    public static function write(string $title, string $description, int $reward, array $objectives, bool $inDraw, int $drawWeight, \DateTimeImmutable $now, ?string $id = null, array $tiers = [], bool $collective = false, ?int $minContribution = null): self
    {
        [$title, $description] = self::assertTerms($title, $description, $reward, $objectives, $drawWeight, $tiers);
        self::assertCollective($collective, $minContribution, $objectives, $tiers);

        return new self(
            $id ?? bin2hex(random_bytes(16)), $title, $description, $reward, self::stored($objectives), $inDraw && !$collective, $now,
            drawWeight: $drawWeight, tiers: self::storedTiers($tiers), collective: $collective, minContribution: $collective ? $minContribution : null,
        );
    }

    /**
     * @param list<QuestObjective> $objectives
     * @param list<QuestTier>      $tiers
     */
    public function edit(string $title, string $description, int $reward, array $objectives, bool $inDraw, int $drawWeight, array $tiers = [], ?int $minContribution = null): void
    {
        [$title, $description] = self::assertTerms($title, $description, $reward, $objectives, $drawWeight, $tiers);
        // Story 41.69: a quest stays collective or not - the weeks it was served in read it so.
        self::assertCollective($this->collective, $minContribution, $objectives, $tiers);
        [$this->title, $this->description] = [$title, $description];
        $this->reward = $reward;
        $this->objectives = self::stored($objectives);
        $this->inDraw = $inDraw && !$this->collective;
        $this->minContribution = $this->collective ? $minContribution : null;
        $this->drawWeight = $drawWeight;
        $this->tiers = self::storedTiers($tiers);
    }

    /** @return list<QuestTier> story 41.68: the tiers, none for a quest done or not */
    public function getTiers(): array
    {
        return null === $this->tiers ? [] : QuestTier::listFromArray($this->tiers);
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

    /** May come out of a Monday draw - story 41.69: never a collective quest. */
    public function isDrawable(): bool
    {
        return $this->inDraw && !$this->collective && !$this->isRetired();
    }

    /** Story 41.69: a goal for the whole community. */
    public function isCollective(): bool
    {
        return $this->collective;
    }

    /** Story 41.69: the share a member needs to be paid by a collective quest, null for any other. */
    public function getMinContribution(): ?int
    {
        return $this->minContribution;
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
        return QuestObjective::allReached($this->getObjectives(), $counts);
    }

    /** Story 41.67: the pool the quest's game is drawn from each week it is served, null when it aims at no drawn game. */
    public function drawPool(): ?string
    {
        foreach ($this->getObjectives() as $objective) {
            if (null !== $objective->pool()) {
                return $objective->pool();
            }
        }

        return null;
    }

    /**
     * Story 41.67: the games the quest aims at by name, left out of its draw so the drawn one is another.
     *
     * @return list<string>
     */
    public function aimedGames(): array
    {
        $games = [];
        foreach ($this->getObjectives() as $objective) {
            if (QuestObjective::SCOPE_GAME === $objective->scope && null !== $objective->scopeId) {
                $games[] = $objective->scopeId;
            }
        }

        return $games;
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
     * @param list<QuestTier>      $tiers
     *
     * @return array{0: string, 1: string} the trimmed title and description
     */
    private static function assertTerms(string $title, string $description, int $reward, array $objectives, int $drawWeight, array $tiers = []): array
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
        // Story 41.67: one game drawn a week for the whole quest, so every drawn objective names the same pool.
        $pools = array_unique(array_filter(array_map(static fn (QuestObjective $objective): ?string => $objective->pool(), $objectives), static fn (?string $pool): bool => null !== $pool));
        if (\count($pools) > 1) {
            throw new \DomainException('quest_draw_pools_mixed');
        }
        if ($drawWeight < self::MIN_WEIGHT || $drawWeight > self::MAX_WEIGHT) {
            throw new \DomainException('quest_weight_invalid');
        }
        if ([] !== $tiers) {
            self::assertTiers($reward, $objectives, $tiers);
        }

        return [$title, $description];
    }

    /**
     * Story 41.68: 2 or 3 tiers on the only objective, the first its target and reward, each further one higher, and
     * all of them together within the most a quest pays.
     *
     * @param list<QuestObjective> $objectives
     * @param list<QuestTier>      $tiers
     */
    private static function assertTiers(int $reward, array $objectives, array $tiers): void
    {
        if (1 !== \count($objectives) || \count($tiers) < self::MIN_TIERS || \count($tiers) > self::MAX_TIERS) {
            throw new \DomainException('quest_tiers_invalid');
        }
        if ($tiers[0]->target !== $objectives[0]->target || $tiers[0]->reward !== $reward) {
            throw new \DomainException('quest_tiers_invalid');
        }
        $previous = 0;
        foreach ($tiers as $tier) {
            if ($tier->target <= $previous || $tier->target > QuestObjective::MAX_TARGET || $tier->reward < self::MIN_REWARD) {
                throw new \DomainException('quest_tiers_invalid');
            }
            $previous = $tier->target;
        }
        if (array_sum(array_map(static fn (QuestTier $tier): int => $tier->reward, $tiers)) > self::MAX_REWARD) {
            throw new \DomainException('quest_tiers_reward_invalid');
        }
    }

    /**
     * Story 41.69: a collective quest counts one metric that adds up, has no tiers, and pays from a share between 1
     * and its target - required, so an alt account that makes one check earns nothing.
     *
     * @param list<QuestObjective> $objectives
     * @param list<QuestTier>      $tiers
     */
    private static function assertCollective(bool $collective, ?int $minContribution, array $objectives, array $tiers): void
    {
        if (!$collective) {
            return;
        }
        if (1 !== \count($objectives) || !$objectives[0]->metric->isCollective() || [] !== $tiers) {
            throw new \DomainException('quest_collective_invalid');
        }
        if (null === $minContribution || $minContribution < 1 || $minContribution > $objectives[0]->target) {
            throw new \DomainException('quest_contribution_invalid');
        }
    }

    /**
     * @param list<QuestTier> $tiers
     *
     * @return list<array{target: int, reward: int}>|null
     */
    private static function storedTiers(array $tiers): ?array
    {
        return [] === $tiers ? null : array_map(static fn (QuestTier $tier): array => $tier->toArray(), $tiers);
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
