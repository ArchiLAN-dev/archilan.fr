<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

use App\Community\Application\Exception\DiscordServerSanctionException;

/**
 * The site's sanctions applied on the Discord server by the project's bot (story 39.5).
 */
interface DiscordServerSanctionsInterface
{
    /**
     * Opens every reason the bot writes in the server's audit log, so a ban posed from the site is told apart
     * from one posed on Discord (story 39.7).
     */
    public const string AUDIT_PREFIX = '[archilan.fr]';

    /** False without a bot token or a server id. */
    public function isConfigured(): bool;

    /**
     * Bans without deleting the member's messages.
     *
     * @throws DiscordServerSanctionException
     */
    public function ban(string $discordUserId, string $reason): void;

    /**
     * Lifts the ban; a member who was not banned is no error.
     *
     * @throws DiscordServerSanctionException
     */
    public function unban(string $discordUserId): void;
}
