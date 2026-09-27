<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

/**
 * One ban of the Discord server (story 39.7).
 */
final readonly class DiscordBan
{
    public function __construct(
        public string $discordUserId,
        public string $username,
        public ?string $reason,
    ) {
    }

    /** Posed by the site itself (story 39.5): its reason opens with the site's prefix. */
    public function postedBySite(): bool
    {
        return null !== $this->reason && str_starts_with($this->reason, DiscordServerSanctionsInterface::AUDIT_PREFIX);
    }
}
