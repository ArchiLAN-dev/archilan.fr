<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A friend invited by name into a personal run (story 43.1), next to the invite link: pending until the friend
 * joins or declines, closed when it can no longer be honoured (run ended, friendship gone). A declined
 * invitation may be sent again after RESEND_DELAY; the invitation row is then reopened.
 */
#[ORM\Entity]
#[ORM\Table(name: 'personal_run_invitation')]
#[ORM\UniqueConstraint(name: 'uniq_personal_run_invitation', columns: ['personal_run_id', 'invitee_id'])]
#[ORM\Index(name: 'idx_personal_run_invitation_invitee', columns: ['invitee_id', 'status'])]
final class RunInvitation
{
    public const string PENDING = 'pending';
    public const string ACCEPTED = 'accepted';
    public const string DECLINED = 'declined';
    public const string CLOSED = 'closed';

    public const string RESEND_DELAY = '+24 hours';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'personal_run_id', type: 'string', length: 32)]
        private string $runId,
        #[ORM\Column(name: 'invitee_id', type: 'string', length: 32)]
        private string $inviteeId,
        #[ORM\Column(name: 'inviter_id', type: 'string', length: 32)]
        private string $inviterId,
        #[ORM\Column(type: 'string', length: 16)]
        private string $status,
        #[ORM\Column(name: 'invited_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $invitedAt,
        #[ORM\Column(name: 'responded_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $respondedAt = null,
    ) {
    }

    public static function send(string $runId, string $inviteeId, string $inviterId, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $runId, $inviteeId, $inviterId, self::PENDING, $now);
    }

    /**
     * Whether the friend can be invited again: never while pending or once joined; after a decline, only past
     * the delay; a closed invitation (run or friendship gone at the time) can be sent again at once.
     */
    public function canBeSentAgain(\DateTimeImmutable $now): bool
    {
        return match ($this->status) {
            self::CLOSED => true,
            self::DECLINED => null === $this->respondedAt || $this->respondedAt->modify(self::RESEND_DELAY) <= $now,
            default => false,
        };
    }

    /** Sends it again, by the given inviter. Callers check canBeSentAgain() first. */
    public function resend(string $inviterId, \DateTimeImmutable $now): void
    {
        $this->inviterId = $inviterId;
        $this->status = self::PENDING;
        $this->invitedAt = $now;
        $this->respondedAt = null;
    }

    public function accept(\DateTimeImmutable $now): void
    {
        $this->status = self::ACCEPTED;
        $this->respondedAt = $now;
    }

    public function decline(\DateTimeImmutable $now): void
    {
        $this->status = self::DECLINED;
        $this->respondedAt = $now;
    }

    public function close(\DateTimeImmutable $now): void
    {
        $this->status = self::CLOSED;
        $this->respondedAt = $now;
    }

    public function isPending(): bool
    {
        return self::PENDING === $this->status;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function getInviteeId(): string
    {
        return $this->inviteeId;
    }

    public function getInviterId(): string
    {
        return $this->inviterId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getInvitedAt(): \DateTimeImmutable
    {
        return $this->invitedAt;
    }

    public function getRespondedAt(): ?\DateTimeImmutable
    {
        return $this->respondedAt;
    }
}
