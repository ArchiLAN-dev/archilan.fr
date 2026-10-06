<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wallet;

use App\Wallet\Application\Handler\AnnounceQuestsOnDiscordJobHandler;
use App\Wallet\Application\Support\QuestAnnouncement;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Infrastructure\Adapter\DisabledQuestAnnouncementChannel;
use App\Wallet\Infrastructure\Adapter\QuestAnnouncementChannelFactory;
use App\Wallet\Infrastructure\Http\DiscordWebhookQuestAnnouncementChannel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 41.24: the week's quests told on Discord through an incoming webhook.
 */
final class DiscordWebhookQuestAnnouncementChannelTest extends TestCase
{
    private const string URL = 'https://discord.example/api/webhooks/1/secret-token';

    public function testTheMessageListsTheQuestsTheChestAndResolvesNoMention(): void
    {
        $sent = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = ['method' => $method, 'url' => $url, 'body' => json_decode(is_string($options['body'] ?? null) ? $options['body'] : '', true)];

            return new MockResponse('', ['http_code' => 204]);
        });

        new DiscordWebhookQuestAnnouncementChannel($client, self::URL)->post($this->announcement());

        self::assertIsArray($sent);
        self::assertSame('POST', $sent['method']);
        self::assertSame(self::URL, $sent['url']);
        $body = $sent['body'];
        self::assertIsArray($body);
        self::assertSame(['parse' => []], $body['allowed_mentions'] ?? null);
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

    public function testAFailureNeverShowsTheWebhookUrl(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 500]));

        try {
            new DiscordWebhookQuestAnnouncementChannel($client, self::URL)->post($this->announcement());
            self::fail('a 500 must throw');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('HTTP 500', $e->getMessage());
            self::assertStringNotContainsString('secret-token', $e->getMessage());
        }
    }

    public function testNoWebhookMeansNoMessageAndObjectivesReadInWords(): void
    {
        self::assertInstanceOf(DisabledQuestAnnouncementChannel::class, QuestAnnouncementChannelFactory::create(new MockHttpClient(), '  '));
        self::assertInstanceOf(DiscordWebhookQuestAnnouncementChannel::class, QuestAnnouncementChannelFactory::create(new MockHttpClient(), self::URL));

        self::assertSame('50 checks', AnnounceQuestsOnDiscordJobHandler::describe(new QuestObjective(QuestMetric::Checks, 50), null));
        self::assertSame('1 partie à LAN #3', AnnounceQuestsOnDiscordJobHandler::describe(new QuestObjective(QuestMetric::Sessions, 1, QuestObjective::SCOPE_EVENT, 'e3'), 'LAN #3'));
        self::assertSame('1 goal sur une cible retirée', AnnounceQuestsOnDiscordJobHandler::describe(new QuestObjective(QuestMetric::Goals, 1, QuestObjective::SCOPE_GAME, 'gone'), null));
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
