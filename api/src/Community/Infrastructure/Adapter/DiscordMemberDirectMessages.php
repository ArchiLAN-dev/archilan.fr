<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Adapter;

use App\Community\Application\Exception\MemberDirectMessageException;
use App\Community\Application\Port\MemberDirectMessageInterface;
use App\Community\Application\Support\ModerationForumMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The bot's direct messages to a member (story 39.3): open (or find) the DM channel, then post in it. Discord
 * refuses when the member closed their DMs or shares no server with the bot any more.
 */
final readonly class DiscordMemberDirectMessages implements MemberDirectMessageInterface
{
    private DiscordBotRest $rest;

    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire('%env(default::DISCORD_BOT_TOKEN)%')]
        string $botToken,
    ) {
        $this->rest = new DiscordBotRest($httpClient, $botToken);
    }

    public function isConfigured(): bool
    {
        return $this->rest->hasToken();
    }

    public function send(string $discordUserId, ModerationForumMessage $message): void
    {
        try {
            $channel = $this->rest->request('POST', '/users/@me/channels', ['recipient_id' => $discordUserId]);
            $channelId = $channel['id'] ?? null;
            if (!is_string($channelId) || '' === $channelId) {
                throw new MemberDirectMessageException('Discord did not return the direct message channel.');
            }
            $this->rest->request('POST', '/channels/'.$channelId.'/messages', DiscordBotRest::messageBody($message));
        } catch (DiscordRestFailure $e) {
            throw new MemberDirectMessageException($e->getMessage(), $e, $e->transient);
        }
    }
}
