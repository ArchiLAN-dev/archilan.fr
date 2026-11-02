<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Entity\Event;
use App\Identity\Domain\Entity\User;
use App\Wallet\Domain\Entity\MinimapperzSetting;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 49.1: an admin links an event to Minimapperz (slug, key, what a game is worth) and checks the key works.
 */
final class MinimapperzSettingsTest extends FunctionalTestCase
{
    private const string KEY = 'mmz_ev_test-key-ABC';

    private User $admin;
    private Event $event;
    private MockHttpClient $httpClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->event = $this->createEvent('ArchiLAN #4', new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 days'), published: true);
        $httpClient = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $this->httpClient = $httpClient;
    }

    public function testAnEventWithoutSettingsIsOff(): void
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('GET', $this->path());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['data' => [
            'enabled' => false,
            'slug' => null,
            'apiKeySet' => false,
            'apiKeyEnd' => null,
            'pellesPerGame' => MinimapperzSetting::DEFAULT_PELLES_PER_GAME,
            'pellesPerWin' => MinimapperzSetting::DEFAULT_PELLES_PER_WIN,
            'capPerPlayer' => MinimapperzSetting::DEFAULT_CAP_PER_PLAYER,
        ]], $this->decodedJsonResponse());
    }

    public function testAnAdminLinksTheEventAndTheKeyNeverComesBack(): void
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('PUT', $this->path(), $this->payload(['slug' => 'https://app.minimapperz.com/lan/archilan-test/', 'apiKey' => self::KEY]));

        self::assertResponseStatusCodeSame(200);
        $expected = ['data' => [
            'enabled' => true,
            'slug' => 'archilan-test',
            'apiKeySet' => true,
            'apiKeyEnd' => 'ABC',
            'pellesPerGame' => 1,
            'pellesPerWin' => 3,
            'capPerPlayer' => 60,
        ]];
        self::assertSame($expected, $this->decodedJsonResponse());
        $this->client->jsonRequest('GET', $this->path());
        self::assertSame($expected, $this->decodedJsonResponse());
        self::assertStringNotContainsString(self::KEY, (string) $this->client->getResponse()->getContent());
    }

    public function testAnEmptyKeyKeepsTheOneOnFileAndClearingRemovesIt(): void
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('PUT', $this->path(), $this->payload(['apiKey' => self::KEY]));
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('PUT', $this->path(), $this->payload(['apiKey' => '', 'pellesPerGame' => 2]));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::KEY, $this->storedKey());

        $this->client->jsonRequest('PUT', $this->path(), $this->payload(['enabled' => false, 'clearApiKey' => true]));
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->storedKey());
    }

    public function testSwitchingOnNeedsASlugAKeyAndSomethingToEarn(): void
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('PUT', $this->path(), $this->payload(['slug' => '', 'pellesPerGame' => 0, 'pellesPerWin' => 0]));

        self::assertResponseStatusCodeSame(422);
        $fields = $this->errorFields();
        self::assertSame(['slug', 'apiKey', 'pellesPerGame'], $fields);
    }

    public function testValuesOutOfBoundsAreRefused(): void
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('PUT', $this->path(), $this->payload([
            'enabled' => false,
            'slug' => 'not a slug!',
            'apiKey' => 'with space',
            'pellesPerGame' => 101,
            'pellesPerWin' => -1,
            'capPerPlayer' => 0,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['slug', 'apiKey', 'pellesPerGame', 'pellesPerWin', 'capPerPlayer'], $this->errorFields());
        self::assertNull($this->storedKey());
    }

    public function testTheCheckReadsTheEventWithTheKeyOnFile(): void
    {
        $this->configure();
        $requests = [];
        $this->httpClient->setResponseFactory(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, self::authorization($options)];

            return new MockResponse((string) json_encode([
                'slug' => 'archilan-test',
                'name' => 'ArchiLAN test',
                'opens_at' => '2026-11-02T20:25:00Z',
                'closes_at' => '2026-11-07T20:25:00Z',
                'is_open' => true,
                'ranking_mode' => 'best_game',
            ]), ['http_code' => 200]);
        });

        $this->loginAs($this->admin);
        $this->client->jsonRequest('POST', $this->path().'/check');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['data' => ['status' => 'ok', 'event' => [
            'slug' => 'archilan-test',
            'name' => 'ArchiLAN test',
            'opensAt' => '2026-11-02T20:25:00+00:00',
            'closesAt' => '2026-11-07T20:25:00+00:00',
            'isOpen' => true,
        ]]], $this->decodedJsonResponse());
        self::assertCount(1, $requests);
        self::assertSame('GET', $requests[0][0]);
        self::assertStringEndsWith('/api/v1/organiser/events/archilan-test', $requests[0][1]);
        self::assertSame('Authorization: Bearer '.self::KEY, $requests[0][2]);
    }

    public function testTheCheckTellsARefusedKey(): void
    {
        $this->configure();
        $this->httpClient->setResponseFactory(new MockResponse('{"detail":"Invalid API key for this event"}', ['http_code' => 401]));

        $this->loginAs($this->admin);
        $this->client->jsonRequest('POST', $this->path().'/check');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['data' => ['status' => 'unauthorized', 'event' => null]], $this->decodedJsonResponse());
    }

    public function testTheCheckTellsMinimapperzIsUnreachable(): void
    {
        $this->configure();
        $this->httpClient->setResponseFactory(new MockResponse('oops', ['http_code' => 503]));

        $this->loginAs($this->admin);
        $this->client->jsonRequest('POST', $this->path().'/check');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['data' => ['status' => 'unreachable', 'event' => null]], $this->decodedJsonResponse());
    }

    public function testTheCheckCallsNobodyWithoutAKey(): void
    {
        $called = false;
        $this->httpClient->setResponseFactory(static function () use (&$called): MockResponse {
            $called = true;

            return new MockResponse('{}');
        });

        $this->loginAs($this->admin);
        $this->client->jsonRequest('POST', $this->path().'/check');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['data' => ['status' => 'not_configured', 'event' => null]], $this->decodedJsonResponse());
        self::assertFalse($called);
    }

    public function testOnlyAnAdminReachesTheSettings(): void
    {
        $member = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $this->loginAs($member);

        $this->client->jsonRequest('GET', $this->path());
        self::assertResponseStatusCodeSame(403);
        $this->client->jsonRequest('PUT', $this->path(), $this->payload());
        self::assertResponseStatusCodeSame(403);
        $this->client->jsonRequest('POST', $this->path().'/check');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnknownEventIsNotFound(): void
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('GET', '/api/v1/admin/events/nope/minimapperz');

        self::assertResponseStatusCodeSame(404);
    }

    private function path(): string
    {
        return sprintf('/api/v1/admin/events/%s/minimapperz', $this->event->getId());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'slug' => 'archilan-test',
            'pellesPerGame' => 1,
            'pellesPerWin' => 3,
            'capPerPlayer' => 60,
        ], $overrides);
    }

    private function configure(): void
    {
        $this->entityManager->persist(new MinimapperzSetting($this->event->getId(), true, 'archilan-test', self::KEY, 1, 2, 50));
        $this->entityManager->flush();
    }

    private function storedKey(): mixed
    {
        $key = $this->entityManager->getConnection()->fetchOne('SELECT api_key FROM minimapperz_setting WHERE event_id = :id', ['id' => $this->event->getId()]);

        return false === $key ? null : $key;
    }

    /** @return list<array-key> */
    private function errorFields(): array
    {
        $error = $this->decodedJsonResponse()['error'] ?? null;
        $details = is_array($error) ? ($error['details'] ?? null) : null;

        return is_array($details) ? array_keys($details) : [];
    }

    /** @param array<array-key, mixed> $options */
    private static function authorization(array $options): mixed
    {
        $headers = $options['normalized_headers'] ?? null;
        $values = is_array($headers) ? ($headers['authorization'] ?? null) : null;

        return is_array($values) ? ($values[0] ?? null) : null;
    }
}
