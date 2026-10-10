<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Nudges towards one player of a personal run (story 43.12): when the last one went out and by whom, shared by
 * every sender so a player hears it at most once per COOLDOWN, and whether the player turned them off for this run.
 */
#[ORM\Entity]
#[ORM\Table(name: 'personal_run_nudge')]
#[ORM\UniqueConstraint(name: 'uniq_personal_run_nudge', columns: ['personal_run_id', 'recipient_id'])]
final class RunNudge
{
    public const string COOLDOWN = '+24 hours';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'personal_run_id', type: 'string', length: 32)]
        private string $runId,
        #[ORM\Column(name: 'recipient_id', type: 'string', length: 32)]
        private string $recipientId,
        #[ORM\Column(name: 'muted', type: 'boolean', options: ['default' => false])]
        private bool $muted,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(name: 'last_sender_id', type: 'string', length: 32, nullable: true)]
        private ?string $lastSenderId = null,
        #[ORM\Column(name: 'last_nudged_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $lastNudgedAt = null,
    ) {
    }

    public static function open(string $runId, string $recipientId, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $runId, $recipientId, false, $now);
    }

    /** Whether a nudge may go out now: not muted, and none in the last COOLDOWN. */
    public function canBeNudged(\DateTimeImmutable $now): bool
    {
        return !$this->muted && !$this->isCoolingDown($now);
    }

    public function isCoolingDown(\DateTimeImmutable $now): bool
    {
        return null !== $this->lastNudgedAt && $this->lastNudgedAt->modify(self::COOLDOWN) > $now;
    }

    /** Whole hours since the last nudge while it still counts against the cap (at least one), null otherwise. */
    public function hoursSinceNudge(\DateTimeImmutable $now): ?int
    {
        if (null === $this->lastNudgedAt || !$this->isCoolingDown($now)) {
            return null;
        }

        return max(1, intdiv($now->getTimestamp() - $this->lastNudgedAt->getTimestamp(), 3600));
    }

    /** Records a nudge. Callers check canBeNudged() first. */
    public function nudge(string $senderId, \DateTimeImmutable $now): void
    {
        $this->lastSenderId = $senderId;
        $this->lastNudgedAt = $now;
        $this->updatedAt = $now;
    }

    public function mute(bool $muted, \DateTimeImmutable $now): void
    {
        $this->muted = $muted;
        $this->updatedAt = $now;
    }

    public function isMuted(): bool
    {
        return $this->muted;
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function getRecipientId(): string
    {
        return $this->recipientId;
    }

    public function getLastSenderId(): ?string
    {
        return $this->lastSenderId;
    }

    public function getLastNudgedAt(): ?\DateTimeImmutable
    {
        return $this->lastNudgedAt;
    }
}
