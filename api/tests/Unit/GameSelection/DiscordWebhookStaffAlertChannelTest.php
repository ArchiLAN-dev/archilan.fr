<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Exception\StaffAlertDeliveryException;
use App\GameSelection\Application\Support\StaffAlert;
use App\GameSelection\Application\Support\StaffAlertLevel;
use App\GameSelection\Infrastructure\Adapter\DisabledStaffAlertChannel;
use App\GameSelection\Infrastructure\Adapter\StaffAlertChannelFactory;
use App\GameSelection\Infrastructure\Http\DiscordWebhookStaffAlertChannel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DiscordWebhookStaffAlertChannelTest extends TestCase
{
    private const string WEBHOOK = 'https://discord.com/api/webhooks/123/secret-token';

    public function testPostsAnEmbedToTheConfiguredUrl(): void
    {
        $response = new MockResponse('', ['http_code' => 204]);
        $channel = new DiscordWebhookStaffAlertChannel(new MockHttpClient($response), self::WEBHOOK);

        $channel->post($this->alert(StaffAlertLevel::Alert));

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::WEBHOOK, $response->getRequestUrl());
        self::assertSame([
            'title' => 'Apworld en échec : Crystal Project',
            'description' => "**Type :** Test de génération en échec\n@everyone regarde",
            'url' => 'https://archilan.fr/admin/jeux/game-1',
            'color' => DiscordWebhookStaffAlertChannel::COLOR_ALERT,
        ], $this->firstEmbed($response));
    }

    public function testMentionsInTheTextAreNeverPinged(): void
    {
        // The error text comes from third-party apworld code: it must never ping the whole server.
        $response = new MockResponse('', ['http_code' => 204]);
        $channel = new DiscordWebhookStaffAlertChannel(new MockHttpClient($response), self::WEBHOOK);

        $channel->post($this->alert(StaffAlertLevel::Alert));

        self::assertSame(['parse' => []], $this->requestJson($response)['allowed_mentions'] ?? null);
    }

    public function testEachLevelHasItsColour(): void
    {
        $colours = [];
        foreach (StaffAlertLevel::cases() as $level) {
            $response = new MockResponse('', ['http_code' => 204]);
            new DiscordWebhookStaffAlertChannel(new MockHttpClient($response), self::WEBHOOK)->post($this->alert($level));
            $colours[$level->value] = $this->firstEmbed($response)['color'] ?? null;
        }

        self::assertSame([
            'alert' => DiscordWebhookStaffAlertChannel::COLOR_ALERT,
            'info' => DiscordWebhookStaffAlertChannel::COLOR_INFO,
            'resolved' => DiscordWebhookStaffAlertChannel::COLOR_RESOLVED,
        ], $colours);
    }

    public function testNon2xxResponseIsReportedAsFailure(): void
    {
        $channel = new DiscordWebhookStaffAlertChannel(
            new MockHttpClient(new MockResponse('{"message":"You are being rate limited."}', ['http_code' => 429])),
            self::WEBHOOK,
        );

        $this->expectException(StaffAlertDeliveryException::class);

        $channel->post($this->alert(StaffAlertLevel::Alert));
    }

    public function testTransportErrorIsReportedAsFailure(): void
    {
        $channel = new DiscordWebhookStaffAlertChannel(
            new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host'])),
            self::WEBHOOK,
        );

        $this->expectException(StaffAlertDeliveryException::class);

        $channel->post($this->alert(StaffAlertLevel::Alert));
    }

    /**
     * Story 38.2 review: a rate limit, a server error or an unreachable Discord pass; a refusal does not.
     */
    public function testOnlyAPassingFailureIsWorthARetry(): void
    {
        foreach ([429 => true, 500 => true, 502 => true, 400 => false, 401 => false, 404 => false] as $status => $transient) {
            $channel = new DiscordWebhookStaffAlertChannel(new MockHttpClient(new MockResponse('', ['http_code' => $status])), self::WEBHOOK);
            try {
                $channel->post($this->alert(StaffAlertLevel::Alert));
                self::fail('Expected a delivery failure.');
            } catch (StaffAlertDeliveryException $e) {
                self::assertSame($transient, $e->transient, 'HTTP '.$status);
            }
        }

        $unreachable = new DiscordWebhookStaffAlertChannel(new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host'])), self::WEBHOOK);
        try {
            $unreachable->post($this->alert(StaffAlertLevel::Alert));
            self::fail('Expected a delivery failure.');
        } catch (StaffAlertDeliveryException $e) {
            self::assertTrue($e->transient);
        }
    }

    public function testTheSecretUrlNeverAppearsInTheFailureMessage(): void
    {
        $channel = new DiscordWebhookStaffAlertChannel(
            new MockHttpClient(new MockResponse('', ['http_code' => 500])),
            self::WEBHOOK,
        );

        try {
            $channel->post($this->alert(StaffAlertLevel::Alert));
            self::fail('Expected a delivery failure.');
        } catch (StaffAlertDeliveryException $e) {
            self::assertStringNotContainsString('secret-token', $e->getMessage());
        }
    }

    public function testNoUrlGivesTheDisabledChannel(): void
    {
        self::assertInstanceOf(DisabledStaffAlertChannel::class, StaffAlertChannelFactory::create(new MockHttpClient(), ''));
        self::assertInstanceOf(DisabledStaffAlertChannel::class, StaffAlertChannelFactory::create(new MockHttpClient(), '   '));
    }

    public function testAUrlGivesTheDiscordChannel(): void
    {
        self::assertInstanceOf(DiscordWebhookStaffAlertChannel::class, StaffAlertChannelFactory::create(new MockHttpClient(), self::WEBHOOK));
    }

    public function testTheDisabledChannelSendsNothing(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('The disabled channel must not make any request.');
        });

        StaffAlertChannelFactory::create($client, '')->post($this->alert(StaffAlertLevel::Alert));

        self::assertSame(0, $client->getRequestsCount());
    }

    private function alert(StaffAlertLevel $level): StaffAlert
    {
        return new StaffAlert(
            'Apworld en échec : Crystal Project',
            "**Type :** Test de génération en échec\n@everyone regarde",
            'https://archilan.fr/admin/jeux/game-1',
            $level,
        );
    }

    /**
     * @return array<mixed>
     */
    private function requestJson(MockResponse $response): array
    {
        $body = $response->getRequestOptions()['body'] ?? '';
        self::assertIsString($body);
        $json = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }

    /**
     * @return array<mixed>
     */
    private function firstEmbed(MockResponse $response): array
    {
        $embeds = $this->requestJson($response)['embeds'] ?? null;
        self::assertIsArray($embeds);
        self::assertCount(1, $embeds);
        $embed = $embeds[0] ?? null;
        self::assertIsArray($embed);

        return $embed;
    }
}
