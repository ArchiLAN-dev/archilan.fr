<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only audit row for an admin moderation action on an account (story 30.29): who did what to whom,
 * why, and when. Never edited or deleted - it's the trace behind every warn/suspend/ban/lift. The one thing
 * filled in afterwards is how the bot's direct message told the member (story 39.4), once.
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_moderation_action')]
#[ORM\Index(name: 'idx_community_mod_action_target', columns: ['target_user_id', 'created_at'])]
final class ModerationAction
{
    public const string ACTION_WARN = 'warn';
    public const string ACTION_SUSPEND = 'suspend';
    public const string ACTION_BAN = 'ban';
    public const string ACTION_LIFT = 'lift';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'actor_id', type: 'string', length: 32)]
        private string $actorId,
        #[ORM\Column(name: 'target_user_id', type: 'string', length: 32)]
        private string $targetUserId,
        #[ORM\Column(type: 'string', length: 16)]
        private string $action,
        #[ORM\Column(type: 'string', length: 500)]
        private string $reason,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'related_report_id', type: 'string', length: 32, nullable: true)]
        private ?string $relatedReportId = null,
        #[ORM\Column(name: 'discord_dm_status', type: 'string', length: 16, nullable: true)]
        private ?string $discordDmStatus = null,
    ) {
    }

    public static function create(
        string $actorId,
        string $targetUserId,
        string $action,
        string $reason,
        \DateTimeImmutable $now,
        ?string $relatedReportId = null,
    ): self {
        return new self(bin2hex(random_bytes(16)), $actorId, $targetUserId, $action, $reason, $now, $relatedReportId);
    }

    /** The first outcome stands: the member is told once, whatever retries follow. */
    public function recordDirectMessage(string $status): void
    {
        $this->discordDmStatus ??= $status;
    }

    public function getDiscordDmStatus(): ?string
    {
        return $this->discordDmStatus;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getActorId(): string
    {
        return $this->actorId;
    }

    public function getTargetUserId(): string
    {
        return $this->targetUserId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRelatedReportId(): ?string
    {
        return $this->relatedReportId;
    }
}
