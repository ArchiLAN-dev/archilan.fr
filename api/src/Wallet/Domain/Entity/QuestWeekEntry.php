<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Entity;

use App\Wallet\Domain\Enum\QuestWeekOrigin;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestTier;
use Doctrine\ORM\Mapping as ORM;

/**
 * A quest served in a week (story 41.15): pinned there by an admin, or drawn when the week was first needed. Once
 * there, it stays - editing or retiring the quest changes nothing to the weeks already served.
 *
 * Story 41.67: what it asks and pays is frozen once the week has begun (`objectives`, `reward`), with the game drawn
 * for it if it aims at one. An entry served before that story, or pinned to a week not begun yet, has none: it reads
 * its quest.
 */
#[ORM\Entity]
#[ORM\Table(name: 'quest_week_entry')]
#[ORM\UniqueConstraint(name: 'uniq_quest_week_entry', columns: ['week_key', 'quest_id'])]
final class QuestWeekEntry
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'week_key', type: 'string', length: 8)]
        private string $weekKey,
        #[ORM\Column(name: 'quest_id', type: 'string', length: 32)]
        private string $questId,
        #[ORM\Column(type: 'string', length: 8, enumType: QuestWeekOrigin::class)]
        private QuestWeekOrigin $origin,
        #[ORM\Column(type: 'integer')]
        private int $position,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        /** @var list<array{metric: string, target: int, scope?: string, scopeId?: string}>|null */
        #[ORM\Column(type: 'json', nullable: true)]
        private ?array $objectives = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        private ?int $reward = null,
        #[ORM\Column(name: 'drawn_game_id', type: 'string', length: 32, nullable: true)]
        private ?string $drawnGameId = null,
        /** @var list<array{target: int, reward: int}>|null */
        #[ORM\Column(type: 'json', nullable: true)]
        private ?array $tiers = null,
    ) {
    }

    /**
     * Story 41.67: freezes what the quest asks and pays for this week. Story 41.68: its tiers too.
     *
     * @param list<QuestObjective> $objectives
     * @param list<QuestTier>      $tiers
     */
    public function freeze(array $objectives, int $reward, ?string $drawnGameId, array $tiers = []): void
    {
        $this->objectives = array_map(static fn (QuestObjective $objective): array => $objective->toArray(), $objectives);
        $this->reward = $reward;
        $this->drawnGameId = $drawnGameId;
        $this->tiers = [] === $tiers ? null : array_map(static fn (QuestTier $tier): array => $tier->toArray(), $tiers);
    }

    /** @return list<QuestTier> the frozen tiers (none: a quest done or not) */
    public function getTiers(): array
    {
        return null === $this->tiers ? [] : QuestTier::listFromArray($this->tiers);
    }

    public function isFrozen(): bool
    {
        return null !== $this->objectives && null !== $this->reward;
    }

    /** @return list<QuestObjective>|null the frozen objectives, null when not frozen */
    public function getObjectives(): ?array
    {
        return null === $this->objectives ? null : QuestObjective::listFromArray($this->objectives);
    }

    public function getReward(): ?int
    {
        return $this->reward;
    }

    public function getDrawnGameId(): ?string
    {
        return $this->drawnGameId;
    }

    public static function serve(string $weekKey, string $questId, QuestWeekOrigin $origin, int $position, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $weekKey, $questId, $origin, $position, $now);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getWeekKey(): string
    {
        return $this->weekKey;
    }

    public function getQuestId(): string
    {
        return $this->questId;
    }

    public function getOrigin(): QuestWeekOrigin
    {
        return $this->origin;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
