<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Adapter;

use App\Community\Application\Exception\ModerationForumDeliveryException;
use App\Community\Application\Port\ModerationForumInterface;
use App\Community\Application\Support\ModerationForumMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The staff forum on Discord (story 39.1), driven through the REST API by the project's existing bot - the
 * one that already assigns the member roles. No webhook: a forum post is a thread the bot opens and writes in.
 */
final class DiscordModerationForum implements ModerationForumInterface
{
    /** @var array<string, string>|null lowercased tag name => tag id, read once per instance */
    private ?array $tagIds = null;

    private readonly DiscordBotRest $rest;

    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire('%env(default::DISCORD_BOT_TOKEN)%')]
        string $botToken,
        #[Autowire('%env(default::DISCORD_MODERATION_FORUM_ID)%')]
        private readonly string $forumId,
    ) {
        $this->rest = new DiscordBotRest($httpClient, $botToken);
    }

    public function isConfigured(): bool
    {
        return $this->rest->hasToken() && '' !== $this->forumId;
    }

    public function openThread(string $title, ModerationForumMessage $message): string
    {
        $body = [
            'name' => mb_substr('' !== trim($title) ? $title : 'Membre', 0, 100),
            'message' => DiscordBotRest::messageBody($message),
        ];
        $tagId = $this->tagId($message->tag);
        if (null !== $tagId) {
            $body['applied_tags'] = [$tagId];
        }

        $thread = $this->request('POST', '/channels/'.$this->forumId.'/threads', $body);
        $id = $thread['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            throw new ModerationForumDeliveryException('Discord did not return the id of the new post.');
        }

        return $id;
    }

    public function post(string $threadId, ModerationForumMessage $message): void
    {
        // An archived post does not take messages from the API: reopen it, and retag it on the way.
        $patch = ['archived' => false];
        $tagId = $this->tagId($message->tag);
        if (null !== $tagId) {
            $patch['applied_tags'] = [$tagId];
        }
        $this->request('PATCH', '/channels/'.$threadId, $patch);
        $this->request('POST', '/channels/'.$threadId.'/messages', DiscordBotRest::messageBody($message));
    }

    /**
     * The forum tag carrying this name, whatever its case. A tag the staff has not created is simply not
     * applied: it never blocks a post.
     */
    private function tagId(?string $name): ?string
    {
        if (null === $name) {
            return null;
        }
        if (null === $this->tagIds) {
            $this->tagIds = [];
            $forum = $this->request('GET', '/channels/'.$this->forumId);
            $tags = $forum['available_tags'] ?? [];
            foreach (is_array($tags) ? $tags : [] as $tag) {
                if (is_array($tag) && is_string($tag['id'] ?? null) && is_string($tag['name'] ?? null)) {
                    $this->tagIds[mb_strtolower(trim($tag['name']))] = $tag['id'];
                }
            }
        }

        return $this->tagIds[mb_strtolower(trim($name))] ?? null;
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        try {
            return $this->rest->request($method, $path, $json);
        } catch (DiscordRestFailure $e) {
            throw new ModerationForumDeliveryException($e->getMessage(), $e, $e->transient);
        }
    }
}
