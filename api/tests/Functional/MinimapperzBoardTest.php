<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Entity\Event;
use App\Identity\Domain\Entity\User;
use App\Wallet\Domain\Entity\MinimapperzSetting;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 49.4: the public Minimapperz board of an event, relayed and kept a few seconds by the site.
 */
final class MinimapperzBoardTest extends FunctionalTestCase
{
    private User $alice;
    private Event $event;
    private int $calls = 0;
    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $this->event = $this->createEvent('ArchiLAN #4', new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('+1 day'), published: true);
        $this->createRegistration($this->event->getId(), $this->alice->getId());
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO minimapperz_code (event_id, code, imported_at, user_id, assigned_at) VALUES (:event, :code, now(), :user, now())',
            ['event' => $this->event->getId(), 'code' => 'MMZ-A', 'user' => $this->alice->getId()],
        );

        $httpClient = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory(function (string $method, string $url): MockResponse {
            ++$this->calls;
            if (200 !== $this->status) {
                return new MockResponse('oops', ['http_code' => $this->status]);
            }
            if (str_contains($url, '/ranking')) {
                return new MockResponse((string) json_encode(['mode' => 'best_game', 'format' => null, 'count' => 2, 'data' => [
                    ['rank' => 1, 'username' => 'alice_mmz', 'code' => 'MMZ-A', 'score' => 9120, 'games' => 3, 'wins' => 2],
                    ['rank' => 2, 'username' => 'stranger', 'code' => null, 'score' => 4500.5, 'games' => 1, 'wins' => 0],
                ]]));
            }

            return new MockResponse((string) json_encode(['count' => 2, 'data' => [
                ['game' => 'g1', 'played_at' => '2026-11-07T17:00:00Z', 'rounds' => 5, 'players' => [
                    ['username' => 'stranger', 'code' => null, 'points' => 10, 'place' => 1, 'won' => true],
                ]],
                ['game' => 'g2', 'played_at' => '2026-11-07T18:00:00Z', 'rounds' => 5, 'players' => [
                    ['username' => 'alice_mmz', 'code' => 'MMZ-A', 'points' => 20, 'place' => 1, 'won' => true],
                    ['username' => 'stranger', 'code' => null, 'points' => 10, 'place' => 2, 'won' => false],
                ]],
            ]]));
        });
    }

    public function testAnyoneReadsTheBoardWithTheSiteNamesOfLinkedPlayers(): void
    {
        $this->link(true);

        $this->client->jsonRequest('GET', $this->path());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['data' => [
            'status' => 'ok',
            'eventTitle' => 'ArchiLAN #4',
            'ranking' => [
                ['rank' => 1, 'name' => 'Alice', 'score' => 9120, 'games' => 3, 'wins' => 2],
                ['rank' => 2, 'name' => 'stranger', 'score' => 4500.5, 'games' => 1, 'wins' => 0],
            ],
            'recent' => [
                ['id' => 'g2', 'playedAt' => '2026-11-07T18:00:00+00:00', 'winner' => 'Alice', 'players' => 2],
                ['id' => 'g1', 'playedAt' => '2026-11-07T17:00:00+00:00', 'winner' => 'stranger', 'players' => 1],
            ],
        ]], $this->decodedJsonResponse());
    }

    public function testTheBoardIsKeptAFewSecondsWhateverTheScreens(): void
    {
        $this->link(true);

        $this->client->jsonRequest('GET', $this->path());
        $this->client->jsonRequest('GET', $this->path());
        $this->client->jsonRequest('GET', $this->path());

        self::assertSame(2, $this->calls, 'one ranking and one games call for three reads');
    }

    public function testASwitchedOffLinkShowsNothingAndCallsNobody(): void
    {
        $this->link(false);

        $this->client->jsonRequest('GET', $this->path());

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('unavailable', $data['status']);
        self::assertSame([], $data['ranking']);
        self::assertSame(0, $this->calls);
    }

    public function testMinimapperzNotAnsweringIsTold(): void
    {
        $this->link(true);
        $this->status = 503;

        $this->client->jsonRequest('GET', $this->path());

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('unreachable', $data['status']);
    }

    public function testAnUnknownEventIsNotFound(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/events/nope/minimapperz/board');

        self::assertResponseStatusCodeSame(404);
    }

    private function link(bool $enabled): void
    {
        $this->entityManager->persist(new MinimapperzSetting($this->event->getId(), $enabled, 'archilan-4', 'mmz_ev_key', 1, 2, 50));
        $this->entityManager->flush();
    }

    private function path(): string
    {
        return sprintf('/api/v1/events/%s/minimapperz/board', $this->event->getId());
    }
}
