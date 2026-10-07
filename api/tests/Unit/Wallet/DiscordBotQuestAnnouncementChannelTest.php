<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wallet;

use App\Shared\Infrastructure\Http\DiscordBotRest;
use App\Wallet\Application\Exception\QuestAnnouncementDeliveryException;
use App\Wallet\Application\Support\QuestAnnouncement;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Infrastructure\Adapter\DisabledQuestAnnouncementChannel;
use App\Wallet\Infrastructure\Adapter\QuestAnnouncementChannelFactory;
use App\Wallet\Infrastructure\Http\DiscordBotQuestAnnouncementChannel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 41.24: the week's quests told on Discord. Story 41.26: by the bot, one message a week.
 */
final class DiscordBotQuestAnnouncementChannelTest extends TestCase
{
    private const string TOKEN = 'secret-bot-token';
    private const string MESSAGES = 'https://discord.com/api/v10/channels/123/messages';

    public function testANewWeekIsPostedByTheBotAndResolvesNoMention(): void
    {
        $calls = [];
        $channel = $this->channel([new MockResponse('{"id":"m1"}', ['http_code' => 200])], $calls);

        self::assertSame('m1', $channel->publish($this->announcement(), null));

        self::assertCount(1, $calls);
        self::assertSame(['POST', self::MESSAGES], [$calls[0]['method'], $calls[0]['url']]);
        self::assertContains('Authorization: Bot '.self::TOKEN, $calls[0]['headers']);
        $body = $calls[0]['body'];
        self::assertSame(['parse' => []], $body['allowed_mentions'] ?? null);
        self::assertArrayNotHasKey('username', $body, 'the bot speaks under its own name');
        $embeds = $body['embeds'] ?? null;
        self::assertIsArray($embeds);
        $embed = $embeds[0] ?? null;
        self::assertIsArray($embed);
        self::assertSame('https://archilan.fr/compte/portefeuille', $embed['url'] ?? null);
        self::assertIsString($embed['description'] ?? null);
        self::assertStringContainsString('Jusqu\'à 120 pelles', $embed['description']);
        self::assertSame([
            ['name' => 'Un goal sur HK · +40 pelles', 'value' => '1 goal sur Hollow Knight', 'inline' => false],
            ['name' => '@everyone · +30 pelles', 'value' => '2 hebdos', 'inline' => false],
            ['name' => 'Coffre de la semaine · +50 pelles', 'value' => 'Pour qui fait toutes les quêtes.', 'inline' => false],
        ], $embed['fields'] ?? null);
    }

    public function testTheWeeksMessageIsUpdatedAndPostedAnewWhenItWasDeleted(): void
    {
        $calls = [];
        $channel = $this->channel([new MockResponse('{"id":"m1"}', ['http_code' => 200])], $calls);
        self::assertSame('m1', $channel->publish($this->announcement(), 'm1'));
        self::assertSame(['PATCH', self::MESSAGES.'/m1'], [$calls[0]['method'], $calls[0]['url']]);

        $calls = [];
        $channel = $this->channel([
            new MockResponse('{"message":"Unknown Message"}', ['http_code' => 404]),
            new MockResponse('{"id":"m2"}', ['http_code' => 200]),
        ], $calls);
        self::assertSame('m2', $channel->publish($this->announcement(), 'm1'));
        self::assertSame(['PATCH', 'POST'], array_column($calls, 'method'));
    }

    public function testAFailureNeverShowsTheToken(): void
    {
        $calls = [];
        $channel = $this->channel([new MockResponse('{"message":"Missing Permissions"}', ['http_code' => 403])], $calls);

        try {
            $channel->publish($this->announcement(), null);
            self::fail('a 403 must throw');
        } catch (QuestAnnouncementDeliveryException $e) {
            self::assertSame('Discord answered HTTP 403.', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testNoBotOrNoChannelMeansNoMessageAndObjectivesReadInWords(): void
    {
        self::assertInstanceOf(DisabledQuestAnnouncementChannel::class, QuestAnnouncementChannelFactory::create(new MockHttpClient(), self::TOKEN, '  '));
        self::assertInstanceOf(DisabledQuestAnnouncementChannel::class, QuestAnnouncementChannelFactory::create(new MockHttpClient(), '', '123'));
        self::assertInstanceOf(DiscordBotQuestAnnouncementChannel::class, QuestAnnouncementChannelFactory::create(new MockHttpClient(), self::TOKEN, '123'));
        self::assertFalse(new DisabledQuestAnnouncementChannel()->isEnabled());

        self::assertSame('50 checks', QuestAnnouncement::describe(new QuestObjective(QuestMetric::Checks, 50), null));
        self::assertSame('1 partie à LAN #3', QuestAnnouncement::describe(new QuestObjective(QuestMetric::Sessions, 1, QuestObjective::SCOPE_EVENT, 'e3'), 'LAN #3'));
        self::assertSame('1 goal sur une cible retirée', QuestAnnouncement::describe(new QuestObjective(QuestMetric::Goals, 1, QuestObjective::SCOPE_GAME, 'gone'), null));
    }

    /**
     * @param list<MockResponse>                                                                  $responses
     * @param list<array{method: string, url: string, headers: list<string>, body: array<mixed>}> $calls
     */
    private function channel(array $responses, array &$calls): DiscordBotQuestAnnouncementChannel
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$calls): MockResponse {
            $body = json_decode(is_string($options['body'] ?? null) ? $options['body'] : '', true);
            $headers = $options['headers'] ?? [];
            $calls[] = [
                'method' => $method,
                'url' => $url,
                'headers' => is_array($headers) ? array_values(array_filter($headers, is_string(...))) : [],
                'body' => is_array($body) ? $body : [],
            ];
            $response = array_shift($responses);
            self::assertInstanceOf(MockResponse::class, $response, 'one call too many');

            return $response;
        });

        return new DiscordBotQuestAnnouncementChannel(new DiscordBotRest($client, self::TOKEN), '123');
    }

    private function announcement(): QuestAnnouncement
    {
        return new QuestAnnouncement(
            '2026-W42',
            [
                ['title' => 'Un goal sur HK', 'objectives' => '1 goal sur Hollow Knight', 'reward' => 40],
                ['title' => '@everyone', 'objectives' => '2 hebdos', 'reward' => 30],
            ],
            50,
            new \DateTimeImmutable('2026-10-18T22:00:00+00:00'),
            'https://archilan.fr/compte/portefeuille',
        );
    }
}
