<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Adapter;

use App\Community\Application\Support\ModerationForumMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The project's existing bot on the Discord REST API, as the moderation uses it (stories 39.1 and 39.3): one
 * authenticated call, and the card every moderation message takes. Mentions are rendered but never notify
 * anyone (`allowed_mentions` empty).
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
     *
     * @return array<string, mixed>
     *
     * @throws DiscordRestFailure
     */
    public function request(string $method, string $path, ?array $json = null): array
    {
        $options = ['headers' => ['Authorization' => 'Bot '.$this->botToken]];
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
            throw new DiscordRestFailure(sprintf('Discord %d on %s %s', $status, $method, $path), transient: true);
        }
        if ($status >= 400) {
            throw new DiscordRestFailure(sprintf('Discord %d on %s %s: %s', $status, $method, $path, mb_substr($content, 0, 300)));
        }

        $decoded = '' === $content ? [] : json_decode($content, true);

        return is_array($decoded) ? array_filter($decoded, is_string(...), \ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function messageBody(ModerationForumMessage $message): array
    {
        return [
            'embeds' => [[
                'title' => mb_substr($message->title, 0, 256),
                'description' => mb_substr($message->description, 0, 4096),
                'color' => $message->color,
                'fields' => array_map(static fn (array $field): array => [
                    'name' => mb_substr($field['name'], 0, 256),
                    'value' => mb_substr('' !== $field['value'] ? $field['value'] : '-', 0, 1024),
                    'inline' => false,
                ], $message->fields),
            ]],
            'allowed_mentions' => ['parse' => []],
        ];
    }
}
