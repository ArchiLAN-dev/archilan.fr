<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Application\Exception\RunListingDeliveryException;
use App\PersonalRuns\Application\Support\RunListingAnnouncement;
use App\PersonalRuns\Infrastructure\Adapter\DisabledRunListingChannel;
use App\PersonalRuns\Infrastructure\Adapter\RunListingChannelFactory;
use App\PersonalRuns\Infrastructure\Http\DiscordBotRunListingChannel;
use App\Shared\Infrastructure\Http\DiscordBotRest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 43.27: the run listings told by the bot, one message each, the role mentioned on request only.
 */
final class DiscordBotRunListingChannelTest extends TestCase
{
    private const string MESSAGES = 'https://discord.com/api/v10/channels/123/messages';

    public function testAPostMentionsOnlyTheConfiguredRole(): void
    {
        $calls = [];
        $channel = $this->channel([new MockResponse('{"id":"m1"}')], $calls, '777');

        self::assertSame(['messageId' => 'm1', 'mentioned' => true], $channel->post($this->announcement(), true));

        self::assertSame(['POST', self::MESSAGES], [$calls[0]['method'], $calls[0]['url']]);
        $body = $calls[0]['body'];
        self::assertSame('<@&777> Une partie cherche des joueurs !', $body['content'] ?? null);
        self::assertSame(['parse' => [], 'roles' => ['777']], $body['allowed_mentions'] ?? null, 'an @everyone in a pitch stays text');
        $embed = $this->embed($body);
        self::assertSame('Async du dimanche', $embed['title'] ?? null);
        self::assertSame('@everyone venez', $embed['description'] ?? null);
        self::assertSame(['name' => 'Par Owner'], $embed['author'] ?? null);
        self::assertSame([
            ['name' => 'Prévue', 'value' => '<t:1793646000:F>', 'inline' => true],
            ['name' => 'Places', 'value' => '2 places libres sur 3', 'inline' => true],
            ['name' => 'Jeux', 'value' => 'A, B, C, D, E +1', 'inline' => false],
        ], $embed['fields'] ?? null);
    }

    public function testNoMentionWhenNotAskedOrNoRoleIsConfigured(): void
    {
        $calls = [];
        $channel = $this->channel([new MockResponse('{"id":"m1"}'), new MockResponse('{"id":"m2"}')], $calls, '777');
        self::assertFalse($channel->post($this->announcement(), false)['mentioned']);

        $calls2 = [];
        $noRole = $this->channel([new MockResponse('{"id":"m3"}')], $calls2, '');
        self::assertFalse($noRole->post($this->announcement(), true)['mentioned']);

        foreach ([$calls[0]['body'], $calls2[0]['body']] as $body) {
            self::assertArrayNotHasKey('content', $body);
            self::assertSame(['parse' => []], $body['allowed_mentions'] ?? null);
        }
    }

    public function testAnEditPatchesTheMessageAndReportsAMessageGone(): void
    {
        $calls = [];
        $channel = $this->channel([new MockResponse('{"id":"m1"}'), new MockResponse('{"message":"Unknown Message"}', ['http_code' => 404])], $calls, '777');

        self::assertTrue($channel->edit('m1', $this->announcement()));
        self::assertSame(['PATCH', self::MESSAGES.'/m1'], [$calls[0]['method'], $calls[0]['url']]);
        self::assertArrayNotHasKey('content', $calls[0]['body'], 'an edit leaves the mention as posted');

        self::assertFalse($channel->edit('m9', $this->announcement()));
    }

    public function testADeleteToleratesAMessageAlreadyGone(): void
    {
        $calls = [];
        $channel = $this->channel([new MockResponse('', ['http_code' => 204]), new MockResponse('{}', ['http_code' => 404])], $calls, '');

        $channel->delete('m1');
        $channel->delete('m2');

        self::assertSame(['DELETE', self::MESSAGES.'/m1'], [$calls[0]['method'], $calls[0]['url']]);
    }

    public function testARefusalBecomesADeliveryFailure(): void
    {
        $calls = [];
        $channel = $this->channel([new MockResponse('{}', ['http_code' => 403])], $calls, '');

        $this->expectException(RunListingDeliveryException::class);
        $this->expectExceptionMessage('Discord answered HTTP 403.');
        $channel->post($this->announcement(), true);
    }

    public function testTheFactoryDisablesTheChannelWithoutTokenOrChannel(): void
    {
        $client = new MockHttpClient();

        self::assertInstanceOf(DisabledRunListingChannel::class, RunListingChannelFactory::create($client, '', '123', '777'));
        self::assertInstanceOf(DisabledRunListingChannel::class, RunListingChannelFactory::create($client, 'token', ' ', '777'));
        self::assertInstanceOf(DiscordBotRunListingChannel::class, RunListingChannelFactory::create($client, 'token', '123', ''));
    }

    public function testTheSeatsLabelMirrorsTheCard(): void
    {
        self::assertSame('Places illimitées', $this->announcement(seats: null, joined: 0)->seatsLabel());
        self::assertSame('2 inscrits · places illimitées', $this->announcement(seats: null, joined: 2)->seatsLabel());
        self::assertSame('1 place libre sur 2', $this->announcement(seats: 2, joined: 1)->seatsLabel());
        self::assertSame('0 place libre sur 2', $this->announcement(seats: 2, joined: 3)->seatsLabel());
    }

    /**
     * @param list<MockResponse>                                           $responses
     * @param list<array{method: string, url: string, body: array<mixed>}> $calls
     */
    private function channel(array $responses, array &$calls, string $roleId): DiscordBotRunListingChannel
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$calls): MockResponse {
            $body = json_decode(is_string($options['body'] ?? null) ? $options['body'] : '', true);
            $calls[] = ['method' => $method, 'url' => $url, 'body' => is_array($body) ? $body : []];
            $response = array_shift($responses);
            self::assertInstanceOf(MockResponse::class, $response, 'one call too many');

            return $response;
        });

        return new DiscordBotRunListingChannel(new DiscordBotRest($client, 'token'), '123', $roleId);
    }

    private function announcement(?int $seats = 3, int $joined = 1): RunListingAnnouncement
    {
        return new RunListingAnnouncement(
            'run-1',
            'Async du dimanche',
            'Owner',
            '@everyone venez',
            new \DateTimeImmutable('2026-11-02T20:00:00+01:00'),
            $seats,
            $joined,
            ['A', 'B', 'C', 'D', 'E', 'F'],
            'https://archilan.fr/communaute/parties',
        );
    }

    /**
     * @param array<mixed> $body
     *
     * @return array<mixed>
     */
    private function embed(array $body): array
    {
        $embeds = $body['embeds'] ?? null;
        self::assertIsArray($embeds);
        $embed = $embeds[0] ?? null;
        self::assertIsArray($embed);

        return $embed;
    }
}
