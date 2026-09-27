<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A ban of the Discord server the site does not apply - no linked account, or an admin's (story 39.7), or one
 * present when the synchronisation was switched on (story 39.9) - once told to the staff. Removed when the ban
 * goes, so a ban that comes back is treated as a new one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'moderation_discord_ban_notice')]
final class DiscordBanNotice
{
    public const string REASON_UNLINKED = 'unlinked';
    public const string REASON_ADMIN = 'admin';
    /** Present on the server when the synchronisation was switched on (story 39.9): shown, never applied. */
    public const string REASON_PREEXISTING = 'preexisting';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'discord_user_id', type: 'string', length: 32)]
        private string $discordUserId,
        #[ORM\Column(type: 'string', length: 16)]
        private string $reason,
        #[ORM\Column(name: 'noticed_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $noticedAt,
    ) {
    }

    public static function record(string $discordUserId, string $reason, \DateTimeImmutable $now): self
    {
        return new self($discordUserId, $reason, $now);
    }

    public function getDiscordUserId(): string
    {
        return $this->discordUserId;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getNoticedAt(): \DateTimeImmutable
    {
        return $this->noticedAt;
    }
}
