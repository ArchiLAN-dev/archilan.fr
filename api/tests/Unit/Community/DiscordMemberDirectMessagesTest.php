<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Exception\MemberDirectMessageException;
use App\Community\Application\Support\ModerationForumMessage;
use App\Community\Infrastructure\Adapter\DiscordMemberDirectMessages;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 39.3: the bot's direct message to a member, through the REST API.
 */
final class DiscordMemberDirectMessagesTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: array<mixed>|null}> */
    private array $requests = [];

    public function testItOpensTheDirectChannelThenSendsWithoutPinging(): void
    {
        $dms = $this->dms([new MockResponse('{"id":"dm-1"}'), new MockResponse('{"id":"message-1"}')]);

        $sent = $dms->send('123456789', new ModerationForumMessage('Réponse de la modération', 'Bonjour <@&42>', 0x3498DB, [], null));

        self::assertSame(['dm-1', 'message-1'], [$sent->channelId, $sent->messageId], 'story 39.4: where to read the answers from');

        self::assertSame(['POST', 'https://discord.com/api/v10/users/@me/channels'], [$this->requests[0]['method'], $this->requests[0]['url']]);
        self::assertSame(['recipient_id' => '123456789'], $this->requests[0]['body']);
        self::assertSame(['POST', 'https://discord.com/api/v10/channels/dm-1/messages'], [$this->requests[1]['method'], $this->requests[1]['url']]);
        $body = $this->requests[1]['body'] ?? [];
        self::assertSame(['parse' => []], $body['allowed_mentions'] ?? null);
        $embeds = $body['embeds'] ?? null;
        self::assertIsArray($embeds);
        $embed = $embeds[0] ?? null;
        self::assertIsArray($embed);
        self::assertSame('Bonjour <@&42>', $embed['description'] ?? null);
    }

    public function testClosedPrivateMessagesAreDefinitive(): void
    {
        $dms = $this->dms([new MockResponse('{"id":"dm-1"}'), new MockResponse('{"message":"Cannot send messages to this user","code":50007}', ['http_code' => 403])]);

        try {
            $dms->send('123456789', new ModerationForumMessage('t', 'd', 0, [], null));
            self::fail('403 must fail');
        } catch (MemberDirectMessageException $e) {
            self::assertFalse($e->transient);
            self::assertStringContainsString('Cannot send messages', $e->getMessage());
        }
    }

    public function testARateLimitIsPassing(): void
    {
        $dms = $this->dms([new MockResponse('{}', ['http_code' => 429])]);

        try {
            $dms->send('123456789', new ModerationForumMessage('t', 'd', 0, [], null));
            self::fail('429 must fail');
        } catch (MemberDirectMessageException $e) {
            self::assertTrue($e->transient);
        }
    }

    public function testItReadsTheAnswersAfterTheCursorOldestFirst(): void
    {
        $dms = $this->dms([new MockResponse((string) json_encode([
            ['id' => '1003', 'author' => ['id' => 'member-1'], 'content' => '', 'timestamp' => '2026-09-27T12:02:00+00:00', 'attachments' => [['url' => 'https://cdn.discordapp.com/attachments/1/2/preuve.png']]],
            ['id' => '1002', 'author' => ['id' => 'member-1'], 'content' => 'Bonjour', 'timestamp' => '2026-09-27T12:01:00+00:00', 'attachments' => []],
            ['id' => 'broken'],
        ]))]);

        $messages = $dms->messagesAfter('dm-1', '1001');

        self::assertSame(['GET', 'https://discord.com/api/v10/channels/dm-1/messages?after=1001&limit=100'], [$this->requests[0]['method'], $this->requests[0]['url']]);
        self::assertCount(2, $messages, 'a message Discord sends without its fields is skipped');
        self::assertSame(['1002', 'member-1', 'Bonjour', '2026-09-27T12:01:00+00:00'], [$messages[0]->id, $messages[0]->authorId, $messages[0]->content, $messages[0]->sentAt]);
        self::assertSame('https://cdn.discordapp.com/attachments/1/2/preuve.png', $messages[1]->content, 'an attachment is kept as its link');
    }

    public function testItNeedsTheBotToken(): void
    {
        self::assertTrue($this->dms([])->isConfigured());
        self::assertFalse(new DiscordMemberDirectMessages(new MockHttpClient([]), '')->isConfigured());
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function dms(array $responses): DiscordMemberDirectMessages
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $body = is_string($options['body'] ?? null) && '' !== $options['body'] ? json_decode($options['body'], true) : null;
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => is_array($body) ? $body : null];

            return array_shift($responses) ?? new MockResponse('{}');
        });

        return new DiscordMemberDirectMessages($client, 'bot-token');
    }
}
