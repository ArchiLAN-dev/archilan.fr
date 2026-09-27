<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only audit row for an admin moderation action on an account (story 30.29): who did what to whom,
 * why, and when. Never edited or deleted - it's the trace behind every warn/suspend/ban/lift. The only things
 * filled in afterwards, once each, are how Discord took it: the bot's direct message to the member (story
 * 39.4) and the sanction applied on the server (story 39.5).
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

    /** The actor of a sanction posed on the Discord server and applied on the site (story 39.7). */
    public const string ACTOR_DISCORD = 'discord';

    /** Outcomes of the sanction applied on the Discord server (stories 39.5 and 39.6). */
    public const string SERVER_BANNED = 'banned';
    /** A lift recorded by story 39.5, before a lift also ended the timeout. */
    public const string SERVER_UNBANNED = 'unbanned';
    public const string SERVER_LIFTED = 'lifted';
    public const string SERVER_TIMED_OUT = 'timed_out';
    public const string SERVER_NOT_MEMBER = 'not_member';
    public const string SERVER_NOT_LINKED = 'not_linked';
    public const string SERVER_UNAVAILABLE = 'unavailable';
    public const string SERVER_FAILED = 'failed';

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
        #[ORM\Column(name: 'discord_server_status', type: 'string', length: 16, nullable: true)]
        private ?string $discordServerStatus = null,
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

    /** The first outcome stands: the server is acted on once, whatever retries follow. */
    public function recordServerSanction(string $status): void
    {
        $this->discordServerStatus ??= $status;
    }

    public function getDiscordServerStatus(): ?string
    {
        return $this->discordServerStatus;
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
