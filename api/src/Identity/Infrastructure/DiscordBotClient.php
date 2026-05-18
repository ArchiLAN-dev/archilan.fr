<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Application\DiscordBotClientInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class DiscordBotClient implements DiscordBotClientInterface
{
    private const BASE_URL = 'https://discord.com/api/v10';

    public function __construct(
        private string $botToken,
        private HttpClientInterface $httpClient,
    ) {
    }

    public function assignRole(string $guildId, string $discordUserId, string $roleId): void
    {
        $statusCode = $this->httpClient->request(
            'PUT',
            self::BASE_URL.'/guilds/'.$guildId.'/members/'.$discordUserId.'/roles/'.$roleId,
            ['headers' => $this->authHeaders()],
        )->getStatusCode();

        $this->assertNoContent($statusCode, 'assign Discord role');
    }

    public function removeRole(string $guildId, string $discordUserId, string $roleId): void
    {
        $statusCode = $this->httpClient->request(
            'DELETE',
            self::BASE_URL.'/guilds/'.$guildId.'/members/'.$discordUserId.'/roles/'.$roleId,
            ['headers' => $this->authHeaders()],
        )->getStatusCode();

        $this->assertNoContent($statusCode, 'remove Discord role');
    }

    public function fetchGuildInfo(string $guildId): array
    {
        $response = $this->httpClient->request(
            'GET',
            self::BASE_URL.'/guilds/'.$guildId,
            ['headers' => $this->authHeaders(), 'query' => ['with_counts' => 'true']],
        );

        /** @var array<string, mixed> $data */
        $data = $response->toArray();

        return $data;
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        return ['Authorization' => 'Bot '.$this->botToken];
    }

    private function assertNoContent(int $statusCode, string $operation): void
    {
        if (204 === $statusCode) {
            return;
        }

        throw new \RuntimeException(sprintf('Discord Bot API failed to %s: expected 204, got %d.', $operation, $statusCode));
    }
}
