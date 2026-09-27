<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Adapter;

use App\Community\Application\Exception\DiscordServerSanctionException;
use App\Community\Application\Port\DiscordServerSanctionsInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bans and unbans (story 39.5), timeouts (story 39.6) on the ArchiLAN Discord server by the project's bot. The reason goes to the
 * server's audit log, URL-encoded as Discord asks, and opens with {@see DiscordServerSanctionsInterface::AUDIT_PREFIX}.
 */
final readonly class DiscordServerSanctions implements DiscordServerSanctionsInterface
{
    private const string LIFT_REASON = 'Levée de la sanction';

    private DiscordBotRest $rest;

    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire('%env(default::DISCORD_BOT_TOKEN)%')]
        string $botToken,
        #[Autowire('%env(default::DISCORD_GUILD_ID)%')]
        private string $guildId,
    ) {
        $this->rest = new DiscordBotRest($httpClient, $botToken);
    }

    public function isConfigured(): bool
    {
        return $this->rest->hasToken() && '' !== $this->guildId;
    }

    public function ban(string $discordUserId, string $reason): void
    {
        try {
            $this->rest->request('PUT', $this->banPath($discordUserId), ['delete_message_seconds' => 0], $this->audit($reason));
        } catch (DiscordRestFailure $e) {
            throw new DiscordServerSanctionException($e->getMessage(), $e, $e->transient);
        }
    }

    public function timeout(string $discordUserId, \DateTimeImmutable $until, string $reason): bool
    {
        try {
            $this->rest->request('PATCH', $this->memberPath($discordUserId), ['communication_disabled_until' => $until->format(\DateTimeInterface::ATOM)], $this->audit($reason));
        } catch (DiscordRestFailure $e) {
            // Unknown Member: not on the server, nothing to time out.
            if (404 === $e->status) {
                return false;
            }
            throw new DiscordServerSanctionException($e->getMessage(), $e, $e->transient);
        }

        return true;
    }

    public function clearTimeout(string $discordUserId): void
    {
        try {
            $this->rest->request('PATCH', $this->memberPath($discordUserId), ['communication_disabled_until' => null], $this->audit(self::LIFT_REASON));
        } catch (DiscordRestFailure $e) {
            if (404 === $e->status) {
                return;
            }
            throw new DiscordServerSanctionException($e->getMessage(), $e, $e->transient);
        }
    }

    public function unban(string $discordUserId): void
    {
        try {
            $this->rest->request('DELETE', $this->banPath($discordUserId), null, $this->audit(self::LIFT_REASON));
        } catch (DiscordRestFailure $e) {
            // Unknown Ban: nothing to lift, which is what was asked.
            if (404 === $e->status) {
                return;
            }
            throw new DiscordServerSanctionException($e->getMessage(), $e, $e->transient);
        }
    }

    private function memberPath(string $discordUserId): string
    {
        return sprintf('/guilds/%s/members/%s', $this->guildId, $discordUserId);
    }

    private function banPath(string $discordUserId): string
    {
        return sprintf('/guilds/%s/bans/%s', $this->guildId, $discordUserId);
    }

    /**
     * @return array<string, string>
     */
    private function audit(string $reason): array
    {
        return ['X-Audit-Log-Reason' => rawurlencode(mb_substr(self::AUDIT_PREFIX.' '.$reason, 0, 400))];
    }
}
