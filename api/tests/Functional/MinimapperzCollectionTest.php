<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Entity\Event;
use App\Identity\Domain\Entity\User;
use App\Registrations\Domain\Entity\Registration;
use App\Wallet\Application\Command\CollectMinimapperzGames;
use App\Wallet\Domain\Entity\MinimapperzSetting;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 49.3: the finished Minimapperz games are read every minute and credited in event pelles to the registrants who
 * played them with their code.
 */
final class MinimapperzCollectionTest extends FunctionalTestCase
{
    private User $admin;
    private User $alice;
    private User $bob;
    private User $carol;
    private Event $event;
    private MockHttpClient $httpClient;
    /** @var list<array{url: string, since: string|null}> */
    private array $requests = [];
    /** @var list<array<string, mixed>> */
    private array $games = [];
    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $this->bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $this->carol = $this->createUser('carol@example.org', ['ROLE_USER'], 'Carol');
        $this->event = $this->createEvent('ArchiLAN #4', new \DateTimeImmutable('-2 hours'), new \DateTimeImmutable('+1 day'), published: true);
        $this->createRegistration($this->event->getId(), $this->alice->getId());
        $this->createRegistration($this->event->getId(), $this->bob->getId());
        $this->createRegistration($this->event->getId(), $this->carol->getId(), Registration::STATUS_CANCELLED);
        // 1 pelle a game, 2 more for a win, 10 at most.
        $this->entityManager->persist(new MinimapperzSetting($this->event->getId(), true, 'archilan-4', 'mmz_ev_key', 1, 2, 10));
        $this->entityManager->flush();
        $this->assignCodes(['MMZ-A' => $this->alice, 'MMZ-B' => $this->bob, 'MMZ-C' => $this->carol]);

        $httpClient = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $this->httpClient = $httpClient;
        $this->httpClient->setResponseFactory(function (string $method, string $url): MockResponse {
            $query = parse_url($url, \PHP_URL_QUERY);
            parse_str(is_string($query) ? $query : '', $params);
            $since = $params['since'] ?? null;
            $this->requests[] = ['url' => $url, 'since' => is_string($since) ? $since : null];
            if (200 !== $this->status) {
                return new MockResponse('{"detail":"nope"}', ['http_code' => $this->status]);
            }
            if (str_contains($url, '/players')) {
                return new MockResponse((string) json_encode(['data' => [
                    ['username' => 'alice_mmz', 'code' => 'MMZ-A', 'joined_at' => null, 'games' => 2, 'wins' => 1, 'total_points' => 9000, 'rank' => 1, 'score' => 9000.0],
                    ['username' => 'stranger', 'code' => null, 'joined_at' => null, 'games' => 1, 'wins' => 0, 'total_points' => 100, 'rank' => 2, 'score' => 100.0],
                ], 'count' => 2]));
            }

            return new MockResponse((string) json_encode(['data' => $this->games, 'count' => count($this->games)]));
        });
    }

    public function testAGamePaysItsPlayersAndAWinPaysMore(): void
    {
        $this->games = [$this->game('g1', '-30 minutes', [['alice_mmz', 'MMZ-A', 1, true], ['bob_mmz', 'MMZ-B', 2, false]])];

        $result = $this->collect();

        self::assertSame(1, $result->events);
        self::assertSame(1, $result->games);
        self::assertSame(4, $result->credited);
        self::assertSame(3, $this->balance($this->alice));
        self::assertSame(1, $this->balance($this->bob));
        self::assertSame(['Minimapperz : partie gagnée'], $this->labels($this->alice));
        self::assertSame(['Minimapperz : partie jouée (2e place)'], $this->labels($this->bob));
    }

    public function testAGameReadTwiceIsCreditedOnceAndTheNextReadingStartsFromTheNewestGame(): void
    {
        $this->games = [$this->game('g1', '-30 minutes', [['alice_mmz', 'MMZ-A', 1, true]])];
        $this->collect();
        $this->collect();

        self::assertSame(3, $this->balance($this->alice));
        self::assertNull($this->requests[0]['since'], 'the first pass reads every game');
        $since = $this->requests[1]['since'];
        self::assertIsString($since);
        // The newest game ended 30 minutes ago; the reading overlaps it by 10 minutes.
        self::assertEqualsWithDelta(new \DateTimeImmutable('-40 minutes')->getTimestamp(), new \DateTimeImmutable($since)->getTimestamp(), 5);
    }

    public function testTheCapStopsTheCreditsAndTheGameCrossingItPaysWhatIsLeft(): void
    {
        $games = [];
        for ($i = 1; $i <= 4; ++$i) {
            $games[] = $this->game('g'.$i, sprintf('-%d minutes', 50 - $i), [['alice_mmz', 'MMZ-A', 1, true]]);
        }
        $this->games = $games;

        $this->collect();

        self::assertSame(10, $this->balance($this->alice), '3 + 3 + 3, then 1 left under the cap of 10');
    }

    public function testNoPelleForAPlayerWithoutCodeOrWhoseRegistrationWasCancelled(): void
    {
        $this->games = [$this->game('g1', '-30 minutes', [['stranger', null, 1, true], ['ghost', 'MMZ-UNKNOWN', 2, false], ['carol_mmz', 'MMZ-C', 3, false]])];

        $result = $this->collect();

        self::assertSame(0, $result->credited);
        self::assertSame(0, $this->balance($this->carol));
    }

    public function testAGamePlayedBeforeTheEventStartedPaysNothing(): void
    {
        $this->games = [$this->game('g0', '-3 hours', [['alice_mmz', 'MMZ-A', 1, true]])];

        $this->collect();

        self::assertSame(0, $this->balance($this->alice));
    }

    public function testNothingIsReadOnceTheEventIsOver(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE event SET starts_at = '2020-01-01T10:00:00+00:00', ends_at = '2020-01-02T18:00:00+00:00' WHERE id = :id",
            ['id' => $this->event->getId()],
        );
        $this->entityManager->clear();

        $result = $this->collect();

        self::assertSame(0, $result->events);
        self::assertSame([], $this->requests);
    }

    public function testARefusedKeySwitchesTheLinkOffAndTellsTheAdmin(): void
    {
        $this->status = 401;
        $this->collect();
        $this->collect();

        self::assertCount(1, $this->requests, 'switched off after the refusal');
        $this->loginAs($this->admin);
        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/minimapperz', $this->event->getId()));
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertFalse($data['enabled']);
        $collection = $data['collection'];
        self::assertIsArray($collection);
        self::assertSame('unauthorized', $collection['status']);
    }

    public function testMinimapperzNotAnsweringIsRetriedFromTheSamePlace(): void
    {
        $this->games = [$this->game('g1', '-30 minutes', [['alice_mmz', 'MMZ-A', 1, false]])];
        $this->collect();
        $this->status = 503;
        $this->collect();
        $this->status = 200;
        $this->collect();

        self::assertSame($this->requests[1]['since'], $this->requests[2]['since'], 'the cursor did not move on a failed pass');
        self::assertSame(1, $this->balance($this->alice));
    }

    public function testTheAdminSeesWhoIsNotLinked(): void
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/minimapperz/players', $this->event->getId()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['data' => ['status' => 'ok', 'players' => [
            ['username' => 'stranger', 'code' => null, 'games' => 1, 'wins' => 0, 'member' => null, 'linked' => false],
            ['username' => 'alice_mmz', 'code' => 'MMZ-A', 'games' => 2, 'wins' => 1, 'member' => 'Alice', 'linked' => true],
        ]]], $this->decodedJsonResponse());
    }

    /** @param array<string, User> $codes */
    private function assignCodes(array $codes): void
    {
        foreach ($codes as $code => $user) {
            $this->entityManager->getConnection()->executeStatement(
                'INSERT INTO minimapperz_code (event_id, code, imported_at, user_id, assigned_at) VALUES (:event, :code, now(), :user, now())',
                ['event' => $this->event->getId(), 'code' => $code, 'user' => $user->getId()],
            );
        }
    }

    /**
     * @param list<array{string, string|null, int, bool}> $players username, code, place, won
     *
     * @return array<string, mixed>
     */
    private function game(string $id, string $endedAt, array $players): array
    {
        return [
            'game' => $id,
            'played_at' => new \DateTimeImmutable($endedAt)->format(\DATE_ATOM),
            'rounds' => 5,
            'players' => array_map(static fn (array $p): array => ['username' => $p[0], 'code' => $p[1], 'points' => 1000, 'place' => $p[2], 'won' => $p[3]], $players),
        ];
    }

    private function collect(): \App\Wallet\Application\Command\MinimapperzCollection
    {
        $command = self::getContainer()->get(CollectMinimapperzGames::class);
        self::assertInstanceOf(CollectMinimapperzGames::class, $command);
        $result = $command->collectAll();
        $this->entityManager->clear();

        return $result;
    }

    private function balance(User $user): int
    {
        $sum = $this->entityManager->getConnection()->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) FROM pelle_movement WHERE user_id = :user AND reason = 'minimapperz' AND kind = 'event' AND event_id = :event",
            ['user' => $user->getId(), 'event' => $this->event->getId()],
        );

        return is_numeric($sum) ? (int) $sum : -1;
    }

    /** @return list<mixed> */
    private function labels(User $user): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT label FROM pelle_movement WHERE user_id = :user AND reason = 'minimapperz' ORDER BY created_at",
            ['user' => $user->getId()],
        );
    }
}
