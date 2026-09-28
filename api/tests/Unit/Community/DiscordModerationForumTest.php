<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Exception\ModerationForumDeliveryException;
use App\Community\Application\Support\ModerationForumMessage;
use App\Community\Infrastructure\Adapter\DiscordModerationForum;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 39.1: the staff forum on Discord, driven by the project's existing bot through the REST API.
 */
final class DiscordModerationForumTest extends TestCase
{
    private const string FORUM = '1400000000000000001';

    /** @var list<array{method: string, url: string, body: array<mixed>|null, auth: string}> */
    private array $requests = [];

    public function testOpeningAPostTagsItAndNeverPingsAnyone(): void
    {
        $forum = $this->forum([
            $this->json(['available_tags' => [['id' => 't-ban', 'name' => 'Ban'], ['id' => 't-warn', 'name' => 'Avertissement']]]),
            $this->json(['id' => 'thread-42']),
        ]);

        $threadId = $forum->openThread('Lone', $this->message('Ban'));

        self::assertSame('thread-42', $threadId);
        self::assertSame(['GET', 'https://discord.com/api/v10/channels/'.self::FORUM], [$this->requests[0]['method'], $this->requests[0]['url']]);
        $create = $this->requests[1];
        self::assertSame('POST', $create['method']);
        self::assertSame('https://discord.com/api/v10/channels/'.self::FORUM.'/threads', $create['url']);
        self::assertSame('Bot bot-token', $create['auth']);
        self::assertSame('Lone', $create['body']['name'] ?? null);
        self::assertSame(['t-ban'], $create['body']['applied_tags'] ?? null);
        $message = $create['body']['message'] ?? null;
        self::assertIsArray($message);
        self::assertSame(['parse' => []], $message['allowed_mentions'] ?? null, 'a mention in a sanction must never notify anyone');
        $embeds = $message['embeds'] ?? null;
        self::assertIsArray($embeds);
        $embed = $embeds[0] ?? null;
        self::assertIsArray($embed);
        self::assertSame('Ban', $embed['title'] ?? null);
    }

    public function testPostingReopensThePostRetagsItThenPosts(): void
    {
        $forum = $this->forum([
            $this->json(['available_tags' => [['id' => 't-lift', 'name' => 'levée']]]),
            $this->json(['id' => 'thread-42']),
            $this->json(['id' => 'message-1']),
        ]);

        $forum->post('thread-42', $this->message('Levée'));

        self::assertSame('PATCH', $this->requests[1]['method']);
        self::assertSame('https://discord.com/api/v10/channels/thread-42', $this->requests[1]['url']);
        self::assertSame(['archived' => false, 'applied_tags' => ['t-lift']], $this->requests[1]['body'], 'tag names match whatever their case');
        self::assertSame('POST', $this->requests[2]['method']);
        self::assertSame('https://discord.com/api/v10/channels/thread-42/messages', $this->requests[2]['url']);
    }

    public function testAMissingTagDoesNotStopThePost(): void
    {
        $forum = $this->forum([
            $this->json(['available_tags' => []]),
            $this->json(['id' => 'thread-42']),
        ]);

        $forum->openThread('Lone', $this->message('Suspension'));

        self::assertArrayNotHasKey('applied_tags', $this->requests[1]['body'] ?? []);
    }

    public function testARateLimitOrAnOutageIsPassing(): void
    {
        foreach ([429, 503] as $status) {
            $this->requests = [];
            $forum = $this->forum([$this->json(['available_tags' => []]), new MockResponse('{}', ['http_code' => $status])]);
            try {
                $forum->openThread('Lone', $this->message('Ban'));
                self::fail((string) $status);
            } catch (ModerationForumDeliveryException $e) {
                self::assertTrue($e->transient, (string) $status);
            }
        }
    }

    public function testAMissingPermissionIsDefinitive(): void
    {
        $forum = $this->forum([$this->json(['available_tags' => []]), new MockResponse('{"message":"Missing Permissions","code":50013}', ['http_code' => 403])]);

        try {
            $forum->openThread('Lone', $this->message('Ban'));
            self::fail('403 must fail');
        } catch (ModerationForumDeliveryException $e) {
            self::assertFalse($e->transient);
            self::assertStringContainsString('Missing Permissions', $e->getMessage());
        }
    }

    public function testItIsOnlyConfiguredWithABotAndAForum(): void
    {
        self::assertTrue($this->forum([])->isConfigured());
        self::assertFalse(new DiscordModerationForum(new MockHttpClient([]), 'bot-token', '')->isConfigured());
        self::assertFalse(new DiscordModerationForum(new MockHttpClient([]), '', self::FORUM)->isConfigured());
    }

    private function message(string $tag): ModerationForumMessage
    {
        return new ModerationForumMessage($tag, 'Triche', 0xE74C3C, [['name' => 'Membre', 'value' => 'Lone']], $tag);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function forum(array $responses): DiscordModerationForum
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $headers = [];
            $sent = $options['headers'] ?? [];
            foreach (is_array($sent) ? $sent : [] as $header) {
                if (is_string($header) && str_contains($header, ':')) {
                    [$name, $value] = explode(':', $header, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
            }
            $body = is_string($options['body'] ?? null) && '' !== $options['body'] ? json_decode($options['body'], true) : null;
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => is_array($body) ? $body : null, 'auth' => $headers['authorization'] ?? ''];

            return array_shift($responses) ?? new MockResponse('{}');
        });

        return new DiscordModerationForum($client, 'bot-token', self::FORUM);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => 200]);
    }
}
