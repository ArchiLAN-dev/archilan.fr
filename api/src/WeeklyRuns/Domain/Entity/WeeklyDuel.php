<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A duel between friends on one weekly run (story 43.15): its creator challenges up to MAX_OPPONENTS friends, the
 * best time at the goal wins. Resolved when the weekly run ends; nobody at the goal leaves it without a winner.
 */
#[ORM\Entity]
#[ORM\Table(name: 'weekly_duel')]
#[ORM\Index(name: 'idx_weekly_duel_run', columns: ['weekly_run_id'])]
#[ORM\Index(name: 'idx_weekly_duel_creator', columns: ['creator_id', 'created_at'])]
final class WeeklyDuel
{
    public const int MAX_OPPONENTS = 5;

    /** Duels one member may create for one week of weekly runs. */
    public const int MAX_PER_WEEK = 3;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'weekly_run_id', type: 'string', length: 36)]
        private string $weeklyRunId,
        #[ORM\Column(name: 'creator_id', type: 'string', length: 32)]
        private string $creatorId,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'resolved_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $resolvedAt = null,
        #[ORM\Column(name: 'winner_id', type: 'string', length: 32, nullable: true)]
        private ?string $winnerId = null,
    ) {
    }

    public static function open(string $weeklyRunId, string $creatorId, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $weeklyRunId, $creatorId, $now);
    }

    /** Closes the duel once the weekly run ended, with its winner or none. A second call changes nothing. */
    public function resolve(?string $winnerId, \DateTimeImmutable $now): void
    {
        if (null !== $this->resolvedAt) {
            return;
        }
        $this->resolvedAt = $now;
        $this->winnerId = $winnerId;
    }

    public function isResolved(): bool
    {
        return null !== $this->resolvedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getWeeklyRunId(): string
    {
        return $this->weeklyRunId;
    }

    public function getCreatorId(): string
    {
        return $this->creatorId;
    }

    public function isCreatedBy(string $userId): bool
    {
        return $this->creatorId === $userId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getWinnerId(): ?string
    {
        return $this->winnerId;
    }
}
