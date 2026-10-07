<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The project's existing bot on the Discord REST API (stories 39.1 and 39.3): one authenticated call. Shared since
 * story 41.26, when the quest announcement started speaking through the bot too; each caller builds its own message
 * and keeps mentions inert (`allowed_mentions` empty).
 */
final readonly class DiscordBotRest
{
    private const string API = 'https://discord.com/api/v10';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(default::DISCORD_BOT_TOKEN)%')]
        private string $botToken,
    ) {
    }

    public function hasToken(): bool
    {
        return '' !== $this->botToken;
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $headers
     *
     * @return array<string, mixed>
     *
     * @throws DiscordRestFailure
     */
    public function request(string $method, string $path, ?array $json = null, array $headers = []): array
    {
        $decoded = $this->call($method, $path, $json, $headers);

        return is_array($decoded) ? array_filter($decoded, is_string(...), \ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * A call answering with a list, such as the messages of a channel.
     *
     * @return list<array<mixed>>
     *
     * @throws DiscordRestFailure
     */
    public function requestList(string $method, string $path): array
    {
        $decoded = $this->call($method, $path, null, []);

        return is_array($decoded) ? array_values(array_filter($decoded, is_array(...))) : [];
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $headers
     *
     * @throws DiscordRestFailure
     */
    private function call(string $method, string $path, ?array $json, array $headers): mixed
    {
        $options = ['headers' => ['Authorization' => 'Bot '.$this->botToken, ...$headers]];
        if (null !== $json) {
            $options['json'] = $json;
        }

        try {
            $response = $this->httpClient->request($method, self::API.$path, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new DiscordRestFailure('Discord unreachable: '.$e->getMessage(), $e, transient: true);
        }

        if (429 === $status || $status >= 500) {
            throw new DiscordRestFailure(sprintf('Discord %d on %s %s', $status, $method, $path), transient: true, status: $status);
        }
        if ($status >= 400) {
            throw new DiscordRestFailure(sprintf('Discord %d on %s %s: %s', $status, $method, $path, mb_substr($content, 0, 300)), status: $status);
        }

        return '' === $content ? [] : json_decode($content, true);
    }
}
