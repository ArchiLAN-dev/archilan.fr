<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Adapter;

use App\Community\Application\Exception\ModerationForumDeliveryException;
use App\Community\Application\Port\ModerationForumInterface;
use App\Community\Application\Support\ModerationForumMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The staff forum on Discord (story 39.1), driven through the REST API by the project's existing bot - the
 * one that already assigns the member roles. No webhook: a forum post is a thread the bot opens and writes in.
 *
 * Mentions are rendered but never notify anyone (`allowed_mentions` empty): a sanction is not a ping.
 */
final class DiscordModerationForum implements ModerationForumInterface
{
    private const string API = 'https://discord.com/api/v10';

    /** @var array<string, string>|null lowercased tag name => tag id, read once per instance */
    private ?array $tagIds = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::DISCORD_BOT_TOKEN)%')]
        private readonly string $botToken,
        #[Autowire('%env(default::DISCORD_MODERATION_FORUM_ID)%')]
        private readonly string $forumId,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->botToken && '' !== $this->forumId;
    }

    public function openThread(string $title, ModerationForumMessage $message): string
    {
        $body = [
            'name' => mb_substr('' !== trim($title) ? $title : 'Membre', 0, 100),
            'message' => $this->messageBody($message),
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
        $this->request('POST', '/channels/'.$threadId.'/messages', $this->messageBody($message));
    }

    /**
     * @return array<string, mixed>
     */
    private function messageBody(ModerationForumMessage $message): array
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
        $options = ['headers' => ['Authorization' => 'Bot '.$this->botToken]];
        if (null !== $json) {
            $options['json'] = $json;
        }

        try {
            $response = $this->httpClient->request($method, self::API.$path, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new ModerationForumDeliveryException('Discord unreachable: '.$e->getMessage(), $e, transient: true);
        }

        if (429 === $status || $status >= 500) {
            throw new ModerationForumDeliveryException(sprintf('Discord %d on %s %s', $status, $method, $path), transient: true);
        }
        if ($status >= 400) {
            throw new ModerationForumDeliveryException(sprintf('Discord %d on %s %s: %s', $status, $method, $path, mb_substr($content, 0, 300)));
        }

        $decoded = '' === $content ? [] : json_decode($content, true);

        return is_array($decoded) ? array_filter($decoded, is_string(...), \ARRAY_FILTER_USE_KEY) : [];
    }
}
