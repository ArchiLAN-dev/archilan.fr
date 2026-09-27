<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Adapter;

use App\Community\Application\Exception\MemberDirectMessageException;
use App\Community\Application\Port\IncomingDirectMessage;
use App\Community\Application\Port\MemberDirectMessageInterface;
use App\Community\Application\Port\SentDirectMessage;
use App\Community\Application\Support\ModerationForumMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The bot's direct messages with a member: open (or find) the DM channel and post in it (story 39.3), then read
 * the channel back for the member's answers (story 39.4). Discord refuses to send when the member closed their
 * DMs or shares no server with the bot any more.
 */
final readonly class DiscordMemberDirectMessages implements MemberDirectMessageInterface
{
    private DiscordBotRest $rest;

    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire('%env(default::DISCORD_BOT_TOKEN)%')]
        string $botToken,
        #[Autowire('%env(bool:default::DISCORD_MODERATION_SYNC)%')]
        private bool $syncEnabled,
    ) {
        $this->rest = new DiscordBotRest($httpClient, $botToken);
    }

    /** Story 39.9: the bot's token serves the roles too; its moderation messages are switched on apart. */
    public function isConfigured(): bool
    {
        return $this->syncEnabled && $this->rest->hasToken();
    }

    public function send(string $discordUserId, ModerationForumMessage $message): SentDirectMessage
    {
        try {
            $channel = $this->rest->request('POST', '/users/@me/channels', ['recipient_id' => $discordUserId]);
            $channelId = $channel['id'] ?? null;
            if (!is_string($channelId) || '' === $channelId) {
                throw new MemberDirectMessageException('Discord did not return the direct message channel.');
            }
            $sent = $this->rest->request('POST', '/channels/'.$channelId.'/messages', DiscordBotRest::messageBody($message));
        } catch (DiscordRestFailure $e) {
            throw new MemberDirectMessageException($e->getMessage(), $e, $e->transient);
        }

        $messageId = $sent['id'] ?? null;
        if (!is_string($messageId) || '' === $messageId) {
            throw new MemberDirectMessageException('Discord did not return the id of the direct message.');
        }

        return new SentDirectMessage($channelId, $messageId);
    }

    public function messagesAfter(string $channelId, string $afterMessageId): array
    {
        try {
            // Discord answers the 100 messages right after the cursor, newest first.
            $raw = $this->rest->requestList('GET', sprintf('/channels/%s/messages?after=%s&limit=100', $channelId, $afterMessageId));
        } catch (DiscordRestFailure $e) {
            throw new MemberDirectMessageException($e->getMessage(), $e, $e->transient);
        }

        $messages = [];
        foreach (array_reverse($raw) as $item) {
            $author = $item['author'] ?? null;
            $id = $item['id'] ?? null;
            $authorId = is_array($author) ? ($author['id'] ?? null) : null;
            $sentAt = $item['timestamp'] ?? null;
            if (!is_string($id) || !is_string($authorId) || !is_string($sentAt)) {
                continue;
            }
            $messages[] = new IncomingDirectMessage($id, $authorId, $this->content($item), $sentAt);
        }

        return $messages;
    }

    /**
     * The text, then the link of each attachment: a screenshot sent alone is still something the member said.
     *
     * @param array<mixed> $item
     */
    private function content(array $item): string
    {
        $parts = is_string($item['content'] ?? null) && '' !== trim($item['content']) ? [trim($item['content'])] : [];
        $attachments = $item['attachments'] ?? [];
        foreach (is_array($attachments) ? $attachments : [] as $attachment) {
            if (is_array($attachment) && is_string($attachment['url'] ?? null)) {
                $parts[] = $attachment['url'];
            }
        }

        return implode("\n", $parts);
    }
}
