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

        $dms->send('123456789', new ModerationForumMessage('Réponse de la modération', 'Bonjour <@&42>', 0x3498DB, [], null));

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
