<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One member of a weekly duel (story 43.15) and their answer. The creator is in from the start; a challenged friend
 * accepts or declines. A block between two members cancels one of them out of the duel.
 */
#[ORM\Entity]
#[ORM\Table(name: 'weekly_duel_participant')]
#[ORM\UniqueConstraint(name: 'uniq_weekly_duel_participant', columns: ['duel_id', 'user_id'])]
#[ORM\Index(name: 'idx_weekly_duel_participant_user', columns: ['user_id'])]
final class WeeklyDuelParticipant
{
    public const string PENDING = 'pending';
    public const string ACCEPTED = 'accepted';
    public const string DECLINED = 'declined';
    public const string CANCELLED = 'cancelled';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'duel_id', type: 'string', length: 32)]
        private string $duelId,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'status', type: 'string', length: 16)]
        private string $status,
        #[ORM\Column(name: 'invited_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $invitedAt,
        #[ORM\Column(name: 'responded_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $respondedAt = null,
    ) {
    }

    public static function creator(WeeklyDuel $duel, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $duel->getId(), $duel->getCreatorId(), self::ACCEPTED, $now, $now);
    }

    public static function challenge(WeeklyDuel $duel, string $userId, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $duel->getId(), $userId, self::PENDING, $now);
    }

    public function accept(\DateTimeImmutable $now): void
    {
        if (self::PENDING === $this->status) {
            $this->status = self::ACCEPTED;
            $this->respondedAt = $now;
        }
    }

    public function decline(\DateTimeImmutable $now): void
    {
        if (self::PENDING === $this->status) {
            $this->status = self::DECLINED;
            $this->respondedAt = $now;
        }
    }

    /** Out of the duel because of a block with another member. */
    public function cancel(\DateTimeImmutable $now): void
    {
        if ($this->isActive()) {
            $this->status = self::CANCELLED;
            $this->respondedAt = $now;
        }
    }

    /** Still in the duel: accepted, or not answered yet. */
    public function isActive(): bool
    {
        return self::PENDING === $this->status || self::ACCEPTED === $this->status;
    }

    public function isAccepted(): bool
    {
        return self::ACCEPTED === $this->status;
    }

    public function isPending(): bool
    {
        return self::PENDING === $this->status;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDuelId(): string
    {
        return $this->duelId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getInvitedAt(): \DateTimeImmutable
    {
        return $this->invitedAt;
    }
}
