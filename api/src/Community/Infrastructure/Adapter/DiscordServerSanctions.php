<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Adapter;

use App\Community\Application\Exception\DiscordServerSanctionException;
use App\Community\Application\Port\DiscordBan;
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
        #[Autowire('%env(bool:default::DISCORD_MODERATION_SYNC)%')]
        private bool $syncEnabled,
        private int $banPageSize = 1000,
    ) {
        $this->rest = new DiscordBotRest($httpClient, $botToken);
    }

    /** Story 39.9: the bot's token and server serve the roles too; acting on sanctions is switched on apart. */
    public function isConfigured(): bool
    {
        return $this->syncEnabled && $this->rest->hasToken() && '' !== $this->guildId;
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

    public function bans(): array
    {
        $bans = [];
        $after = null;
        do {
            $path = sprintf('/guilds/%s/bans?limit=%d', $this->guildId, $this->banPageSize).(null !== $after ? '&after='.$after : '');
            try {
                $page = $this->rest->requestList('GET', $path);
            } catch (DiscordRestFailure $e) {
                throw new DiscordServerSanctionException($e->getMessage(), $e, $e->transient);
            }
            foreach ($page as $item) {
                $user = is_array($item['user'] ?? null) ? $item['user'] : [];
                $id = $user['id'] ?? null;
                if (!is_string($id)) {
                    continue;
                }
                $name = is_string($user['username'] ?? null) ? $user['username'] : $id;
                $bans[] = new DiscordBan($id, $name, is_string($item['reason'] ?? null) ? $item['reason'] : null);
                $after = $id;
            }
        } while (\count($page) >= $this->banPageSize);

        return $bans;
    }

    public function banAuthors(): array
    {
        try {
            $log = $this->rest->request('GET', sprintf('/guilds/%s/audit-logs?action_type=22&limit=100', $this->guildId));
        } catch (DiscordRestFailure) {
            // Without "View Audit Log", the ban keeps its reason and loses its author.
            return [];
        }

        $names = [];
        $users = $log['users'] ?? [];
        foreach (is_array($users) ? $users : [] as $user) {
            if (is_array($user) && is_string($user['id'] ?? null) && is_string($user['username'] ?? null)) {
                $names[$user['id']] = $user['username'];
            }
        }

        $authors = [];
        $entries = $log['audit_log_entries'] ?? [];
        // Newest first: the first entry of a member is their current ban.
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (!is_array($entry) || !is_string($entry['target_id'] ?? null) || !is_string($entry['user_id'] ?? null)) {
                continue;
            }
            $authors[$entry['target_id']] ??= $names[$entry['user_id']] ?? $entry['user_id'];
        }

        return $authors;
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
