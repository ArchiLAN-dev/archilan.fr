<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

use App\Community\Application\Exception\DiscordServerSanctionException;

/**
 * The site's sanctions applied on the Discord server by the project's bot: bans (story 39.5) and timeouts for
 * suspensions (story 39.6).
 */
interface DiscordServerSanctionsInterface
{
    /**
     * Opens every reason the bot writes in the server's audit log, so a ban posed from the site is told apart
     * from one posed on Discord (story 39.7).
     */
    public const string AUDIT_PREFIX = '[archilan.fr]';

    /** Discord's cap on a timeout. */
    public const string TIMEOUT_CAP = 'P28D';

    /** False without a bot token or a server id. */
    public function isConfigured(): bool;

    /**
     * Bans without deleting the member's messages.
     *
     * @throws DiscordServerSanctionException
     */
    public function ban(string $discordUserId, string $reason): void;

    /**
     * Times the member out until the given moment, at most {@see self::TIMEOUT_CAP} ahead.
     *
     * @return bool false when the member is not on the server: there is no one to time out
     *
     * @throws DiscordServerSanctionException
     */
    public function timeout(string $discordUserId, \DateTimeImmutable $until, string $reason): bool;

    /**
     * Ends the member's timeout; a member not timed out, or not on the server, is no error.
     *
     * @throws DiscordServerSanctionException
     */
    public function clearTimeout(string $discordUserId): void;

    /**
     * Lifts the ban; a member who was not banned is no error.
     *
     * @throws DiscordServerSanctionException
     */
    public function unban(string $discordUserId): void;
}
