<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Application\AvatarResolverInterface;
use App\Community\Application\CommunityUserContactsQueryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves a member's avatar from Discord: looks up their `discordId`, fetches the user via the Discord
 * REST API (bot token), and builds the CDN URL from the avatar hash. Best-effort: any failure (no token,
 * no linked Discord, network/HTTP error, no avatar set) resolves to null so the page never breaks.
 *
 * Steam resolution is a future composite (story 30.2 ships Discord first); the port lets it slot in.
 */
final readonly class DiscordAvatarResolver implements AvatarResolverInterface
{
    public function __construct(
        private CommunityUserContactsQueryInterface $contacts,
        private HttpClientInterface $httpClient,
        private string $discordBotToken,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(string $userId): ?string
    {
        if ('' === $this->discordBotToken) {
            return null;
        }

        $contacts = $this->contacts->forUser($userId);
        $discordId = $contacts['discordId'] ?? null;
        if (null === $discordId) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', 'https://discord.com/api/v10/users/'.$discordId, [
                'headers' => ['Authorization' => 'Bot '.$this->discordBotToken],
                'timeout' => 5,
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $avatarHash = $response->toArray()['avatar'] ?? null;
            if (!is_string($avatarHash) || '' === $avatarHash) {
                return null;
            }

            $extension = str_starts_with($avatarHash, 'a_') ? 'gif' : 'png';

            return sprintf('https://cdn.discordapp.com/avatars/%s/%s.%s?size=256', $discordId, $avatarHash, $extension);
        } catch (\Throwable $e) {
            $this->logger->info('community.avatar.discord_resolve_failed', ['userId' => $userId, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
