<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Adapter;

use App\Community\Application\Exception\MemberDirectMessageException;
use App\Community\Application\Port\NotificationDirectMessageInterface;
use App\Community\Application\Support\WebPushMessage;
use App\Shared\Infrastructure\Http\DiscordBotRest;
use App\Shared\Infrastructure\Http\DiscordRestFailure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The bot's direct message carrying a notification (story 40.6): open (or find) the DM channel, as the moderation does
 * (story 39.3), and post the push's text with a link to the site. Switched on apart from the bot's other duties, so a
 * deployment never starts writing to members before the team decides to.
 */
final readonly class DiscordNotificationDirectMessages implements NotificationDirectMessageInterface
{
    public const int COLOR = 0x5865F2;

    private DiscordBotRest $rest;

    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire('%env(default::DISCORD_BOT_TOKEN)%')]
        string $botToken,
        #[Autowire('%env(bool:default::DISCORD_NOTIFICATION_DMS)%')]
        private bool $enabled,
        private string $siteUrl,
    ) {
        $this->rest = new DiscordBotRest($httpClient, $botToken);
    }

    public function isConfigured(): bool
    {
        return $this->enabled && $this->rest->hasToken();
    }

    public function send(string $discordUserId, WebPushMessage $message): void
    {
        try {
            $channel = $this->rest->request('POST', '/users/@me/channels', ['recipient_id' => $discordUserId]);
            $channelId = $channel['id'] ?? null;
            if (!is_string($channelId) || '' === $channelId) {
                throw new MemberDirectMessageException('Discord did not return the direct message channel.');
            }
            $this->rest->request('POST', '/channels/'.$channelId.'/messages', self::body($message, $this->siteUrl));
        } catch (DiscordRestFailure $e) {
            throw new MemberDirectMessageException($e->getMessage(), $e, $e->transient);
        }
    }

    /** @return array<string, mixed> */
    public static function body(WebPushMessage $message, string $siteUrl): array
    {
        return [
            'allowed_mentions' => ['parse' => []],
            'embeds' => [[
                'title' => mb_substr($message->body, 0, 256),
                'url' => rtrim($siteUrl, '/').$message->url,
                'color' => self::COLOR,
                'footer' => ['text' => 'archilan.fr · à régler dans Mon compte, « Ce qui me prévient »'],
            ]],
        ];
    }
}
