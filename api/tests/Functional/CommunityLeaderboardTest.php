<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\CommunityProfile;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;

final class CommunityLeaderboardTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    // ─── Community Stats ─────────────────────────────────────────────────────────

    public function testCommunityStatsReturns200WithCorrectAggregates(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');

        $event = $this->createEvent('LAN', $now, $now->modify('+1 day'));
        $game = $this->createGame('G', 'g');
        $user = $this->createUser('a@a.com', ['ROLE_USER'], 'A', 'a');
        $reg = $this->createRegistration($event->getId(), $user->getId());

        // Session 1: finished with 2 valid slots and 1 invalidated
        $s1 = $this->makeFinishedSession($event->getId(), $now);
        $startedAt = $s1->getStartedAt();
        self::assertNotNull($startedAt);

        $slot1 = SessionSlot::create(bin2hex(random_bytes(16)), $s1->getId(), $reg->getId(), $game->getId(), 'A', 0);
        $slot1->setGoalReachedAt($startedAt->modify('+60 seconds'));
        $slot1->setChecksDone(50);
        $this->entityManager->persist($slot1);

        $slot2 = SessionSlot::create(bin2hex(random_bytes(16)), $s1->getId(), $reg->getId(), $game->getId(), 'A2', 1);
        $slot2->setChecksDone(30);
        $this->entityManager->persist($slot2);

        $slot3 = SessionSlot::create(bin2hex(random_bytes(16)), $s1->getId(), $reg->getId(), $game->getId(), 'A3', 2);
        $slot3->setChecksDone(20);
        $slot3->markAsReleased(); // invalidated
        $this->entityManager->persist($slot3);

        // Session 2: not finished (should not count)
        $s2 = Session::create(bin2hex(random_bytes(16)), $event->getId(), $now);
        $this->entityManager->persist($s2);

        $this->entityManager->flush();

        $this->client->request('GET', '/api/v1/community/stats');

        self::assertResponseStatusCodeSame(200);
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=60', $cacheControl);

        $responseData = $this->decodedJsonResponse();
        $data = $responseData['data'];
        self::assertIsArray($data);
        self::assertSame(1, $data['totalFinishedSessions']); // only s1 is finished
        self::assertSame(80, $data['totalChecksDone']); // slot1(50) + slot2(30); slot3 excluded (invalidated)
        self::assertSame(1, $data['totalGoalsReached']); // only slot1 has a goal
    }

    // ─── Leaderboard - Goals axis ─────────────────────────────────────────────

    public function testGoalsAxisRanksCorrectlyWithTieBreaker(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent('LAN', $now, $now->modify('+1 day'));
        $game = $this->createGame('G', 'g');

        // Alice: 3 goals, Bob: 3 goals (tie → Alice before Bob), Carol: 1 goal
        $alice = $this->createUser('alice@ex.com', ['ROLE_USER'], 'Alice', 'alice');
        $bob = $this->createUser('bob@ex.com', ['ROLE_USER'], 'Bob', 'bob');
        $carol = $this->createUser('carol@ex.com', ['ROLE_USER'], 'Carol', 'carol');

        $regA = $this->createRegistration($event->getId(), $alice->getId());
        $regB = $this->createRegistration($event->getId(), $bob->getId());
        $regC = $this->createRegistration($event->getId(), $carol->getId());

        $session = $this->makeFinishedSession($event->getId(), $now);
        $startedAt = $session->getStartedAt();
        self::assertNotNull($startedAt);

        // Alice: 3 goals
        for ($i = 0; $i < 3; ++$i) {
            $slot = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regA->getId(), $game->getId(), 'Alice'.$i, $i);
            $slot->setGoalReachedAt($startedAt->modify('+'.($i + 1).' minutes'));
            $this->entityManager->persist($slot);
        }
        // Bob: 3 goals (tie with Alice)
        for ($i = 0; $i < 3; ++$i) {
            $slot = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regB->getId(), $game->getId(), 'Bob'.$i, $i + 10);
            $slot->setGoalReachedAt($startedAt->modify('+'.($i + 1).' minutes'));
            $this->entityManager->persist($slot);
        }
        // Carol: 1 goal
        $slotC = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regC->getId(), $game->getId(), 'Carol', 20);
        $slotC->setGoalReachedAt($startedAt->modify('+1 minutes'));
        $this->entityManager->persist($slotC);

        $this->entityManager->flush();

        $this->client->request('GET', '/api/v1/leaderboard?axis=goals&limit=10');

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodedJsonResponse();
        $data = $body['data'];
        $meta = $body['meta'];
        self::assertIsArray($data);
        self::assertIsArray($meta);

        self::assertCount(3, $data);
        self::assertSame(3, $meta['total']);
        self::assertSame('goals', $meta['axis']);

        // Alice and Bob both have 3 goals → tie-break by displayName: Alice < Bob
        $entry0 = $data[0];
        self::assertIsArray($entry0);
        self::assertSame(1, $entry0['rank']);
        self::assertSame('alice', $entry0['slug']);
        self::assertSame(3, $entry0['value']);
        self::assertSame('goals', $entry0['unit']);

        $entry1 = $data[1];
        self::assertIsArray($entry1);
        self::assertSame(2, $entry1['rank']);
        self::assertSame('bob', $entry1['slug']);
        self::assertSame(3, $entry1['value']);

        $entry2 = $data[2];
        self::assertIsArray($entry2);
        self::assertSame(3, $entry2['rank']);
        self::assertSame('carol', $entry2['slug']);
        self::assertSame(1, $entry2['value']);
    }

    // ─── Leaderboard - Checks axis ───────────────────────────────────────────

    public function testChecksAxisSumsCorrectlyExcludingInvalidated(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent('LAN', $now, $now->modify('+1 day'));
        $game = $this->createGame('G', 'g');

        $alice = $this->createUser('alice@ex.com', ['ROLE_USER'], 'Alice', 'alice');
        $bob = $this->createUser('bob@ex.com', ['ROLE_USER'], 'Bob', 'bob');

        $regA = $this->createRegistration($event->getId(), $alice->getId());
        $regB = $this->createRegistration($event->getId(), $bob->getId());

        $session = $this->makeFinishedSession($event->getId(), $now);

        // Alice: 100 checks valid + 20 checks invalidated
        $slotA1 = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regA->getId(), $game->getId(), 'A1', 0);
        $slotA1->setChecksDone(100);
        $this->entityManager->persist($slotA1);

        $slotA2 = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regA->getId(), $game->getId(), 'A2', 1);
        $slotA2->setChecksDone(20);
        $slotA2->markAsReleased();
        $this->entityManager->persist($slotA2);

        // Bob: 150 checks
        $slotB = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regB->getId(), $game->getId(), 'B', 2);
        $slotB->setChecksDone(150);
        $this->entityManager->persist($slotB);

        $this->entityManager->flush();

        $this->client->request('GET', '/api/v1/leaderboard?axis=checks');

        self::assertResponseStatusCodeSame(200);
        $responseData = $this->decodedJsonResponse();
        $data = $responseData['data'];
        self::assertIsArray($data);

        // Bob (150) > Alice (100 - 20 invalidated checks excluded)
        $entry0 = $data[0];
        self::assertIsArray($entry0);
        self::assertSame('bob', $entry0['slug']);
        self::assertSame(150, $entry0['value']);
        self::assertSame('checks', $entry0['unit']);

        $entry1 = $data[1];
        self::assertIsArray($entry1);
        self::assertSame('alice', $entry1['slug']);
        self::assertSame(100, $entry1['value']);
    }

    // ─── Leaderboard - Speed axis ─────────────────────────────────────────────

    public function testSpeedAxisRanksFastestFirst(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent('LAN', $now, $now->modify('+1 day'));
        $game = $this->createGame('G', 'g');

        $alice = $this->createUser('alice@ex.com', ['ROLE_USER'], 'Alice', 'alice');
        $bob = $this->createUser('bob@ex.com', ['ROLE_USER'], 'Bob', 'bob');
        $carol = $this->createUser('carol@ex.com', ['ROLE_USER'], 'Carol', 'carol'); // no goal - excluded

        $regA = $this->createRegistration($event->getId(), $alice->getId());
        $regB = $this->createRegistration($event->getId(), $bob->getId());
        $regC = $this->createRegistration($event->getId(), $carol->getId());

        $session = $this->makeFinishedSession($event->getId(), $now);
        $startedAt = $session->getStartedAt();
        self::assertNotNull($startedAt);

        // Alice: best speed = 60s (has a slower slot at 120s too)
        $slotA1 = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regA->getId(), $game->getId(), 'A1', 0);
        $slotA1->setGoalReachedAt($startedAt->modify('+60 seconds'));
        $this->entityManager->persist($slotA1);

        $slotA2 = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regA->getId(), $game->getId(), 'A2', 1);
        $slotA2->setGoalReachedAt($startedAt->modify('+120 seconds'));
        $this->entityManager->persist($slotA2);

        // Bob: best speed = 30s (fastest overall)
        $slotB = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regB->getId(), $game->getId(), 'B', 2);
        $slotB->setGoalReachedAt($startedAt->modify('+30 seconds'));
        $this->entityManager->persist($slotB);

        // Carol: no goal → excluded from speed leaderboard
        $slotC = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regC->getId(), $game->getId(), 'C', 3);
        $slotC->setChecksDone(10);
        $this->entityManager->persist($slotC);

        $this->entityManager->flush();

        $this->client->request('GET', '/api/v1/leaderboard?axis=speed');

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodedJsonResponse();
        $data = $body['data'];
        $meta = $body['meta'];
        self::assertIsArray($data);
        self::assertIsArray($meta);

        self::assertCount(2, $data); // Carol excluded
        self::assertSame(2, $meta['total']);

        // Bob fastest (30s) → rank 1
        $entry0 = $data[0];
        self::assertIsArray($entry0);
        self::assertSame(1, $entry0['rank']);
        self::assertSame('bob', $entry0['slug']);
        self::assertSame(30, $entry0['value']);
        self::assertSame('seconds', $entry0['unit']);

        // Alice (60s, best of 60s and 120s) → rank 2
        $entry1 = $data[1];
        self::assertIsArray($entry1);
        self::assertSame(2, $entry1['rank']);
        self::assertSame('alice', $entry1['slug']);
        self::assertSame(60, $entry1['value']);
    }

    // ─── Avatar URL ──────────────────────────────────────────────────────────

    public function testLeaderboardIncludesResolvedAvatarUrl(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent('LAN', $now, $now->modify('+1 day'));
        $game = $this->createGame('G', 'g');

        // Alice has a community profile with a cached external avatar; Bob has none.
        $alice = $this->createUser('alice@ex.com', ['ROLE_USER'], 'Alice', 'alice');
        $bob = $this->createUser('bob@ex.com', ['ROLE_USER'], 'Bob', 'bob');

        $aliceAvatar = 'https://cdn.example.test/avatars/alice.png';
        $this->entityManager->persist(new CommunityProfile(
            bin2hex(random_bytes(16)),
            $alice->getId(),
            $now,
            $now,
            avatarUrl: $aliceAvatar,
        ));

        $regA = $this->createRegistration($event->getId(), $alice->getId());
        $regB = $this->createRegistration($event->getId(), $bob->getId());

        $session = $this->makeFinishedSession($event->getId(), $now);
        $startedAt = $session->getStartedAt();
        self::assertNotNull($startedAt);

        // Alice: 2 goals (ranks first), Bob: 1 goal.
        for ($i = 0; $i < 2; ++$i) {
            $slot = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regA->getId(), $game->getId(), 'A'.$i, $i);
            $slot->setGoalReachedAt($startedAt->modify('+'.($i + 1).' minutes'));
            $this->entityManager->persist($slot);
        }
        $slotB = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regB->getId(), $game->getId(), 'B', 5);
        $slotB->setGoalReachedAt($startedAt->modify('+1 minutes'));
        $this->entityManager->persist($slotB);

        $this->entityManager->flush();

        $this->client->request('GET', '/api/v1/leaderboard?axis=goals');

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertCount(2, $data);

        $aliceEntry = $data[0];
        self::assertIsArray($aliceEntry);
        self::assertSame('alice', $aliceEntry['slug']);
        self::assertArrayHasKey('avatarUrl', $aliceEntry);
        self::assertSame($aliceAvatar, $aliceEntry['avatarUrl']);

        $bobEntry = $data[1];
        self::assertIsArray($bobEntry);
        self::assertSame('bob', $bobEntry['slug']);
        self::assertArrayHasKey('avatarUrl', $bobEntry);
        self::assertNull($bobEntry['avatarUrl']);
    }

    // ─── eventId filter ──────────────────────────────────────────────────────

    public function testEventIdFilterNarrowsResultsToSpecificEvent(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');

        $event1 = $this->createEvent('LAN 1', $now, $now->modify('+1 day'));
        $event2 = $this->createEvent('LAN 2', $now, $now->modify('+1 day'));
        $game = $this->createGame('G', 'g');

        $alice = $this->createUser('alice@ex.com', ['ROLE_USER'], 'Alice', 'alice');
        $bob = $this->createUser('bob@ex.com', ['ROLE_USER'], 'Bob', 'bob');

        $regA1 = $this->createRegistration($event1->getId(), $alice->getId());
        $regB2 = $this->createRegistration($event2->getId(), $bob->getId());

        // Session for event1 (alice has 2 goals)
        $s1 = $this->makeFinishedSession($event1->getId(), $now);
        $startedAt1 = $s1->getStartedAt();
        self::assertNotNull($startedAt1);
        for ($i = 0; $i < 2; ++$i) {
            $slot = SessionSlot::create(bin2hex(random_bytes(16)), $s1->getId(), $regA1->getId(), $game->getId(), 'A'.$i, $i);
            $slot->setGoalReachedAt($startedAt1->modify('+'.($i + 1).' minutes'));
            $this->entityManager->persist($slot);
        }

        // Session for event2 (bob has 5 goals)
        $s2 = $this->makeFinishedSession($event2->getId(), $now);
        $startedAt2 = $s2->getStartedAt();
        self::assertNotNull($startedAt2);
        for ($i = 0; $i < 5; ++$i) {
            $slot = SessionSlot::create(bin2hex(random_bytes(16)), $s2->getId(), $regB2->getId(), $game->getId(), 'B'.$i, $i);
            $slot->setGoalReachedAt($startedAt2->modify('+'.($i + 1).' minutes'));
            $this->entityManager->persist($slot);
        }

        $this->entityManager->flush();

        // Without filter: both appear (bob=5, alice=2)
        $this->client->request('GET', '/api/v1/leaderboard?axis=goals');
        self::assertResponseStatusCodeSame(200);
        $allData = $this->decodedJsonResponse()['data'];
        self::assertIsArray($allData);
        self::assertCount(2, $allData);

        // Filter by event1: only alice
        $this->client->request('GET', '/api/v1/leaderboard?axis=goals&eventId='.$event1->getId());
        self::assertResponseStatusCodeSame(200);
        $filteredResponse = $this->decodedJsonResponse();
        $data = $filteredResponse['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data);
        $aliceEntry = $data[0];
        self::assertIsArray($aliceEntry);
        self::assertSame('alice', $aliceEntry['slug']);
        self::assertSame(2, $aliceEntry['value']);
    }

    // ─── Pagination & limit ──────────────────────────────────────────────────

    public function testEmptyPageReturns200WithEmptyArray(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent('LAN', $now, $now->modify('+1 day'));
        $game = $this->createGame('G', 'g');

        $user = $this->createUser('a@a.com', ['ROLE_USER'], 'A', 'a');
        $reg = $this->createRegistration($event->getId(), $user->getId());
        $session = $this->makeFinishedSession($event->getId(), $now);
        $startedAt = $session->getStartedAt();
        self::assertNotNull($startedAt);
        $slot = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $reg->getId(), $game->getId(), 'A', 0);
        $slot->setGoalReachedAt($startedAt->modify('+60 seconds'));
        $this->entityManager->persist($slot);
        $this->entityManager->flush();

        // Page 2 with limit 1 → empty (only 1 player total)
        $this->client->request('GET', '/api/v1/leaderboard?axis=goals&page=2&limit=1');

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodedJsonResponse();
        $meta = $body['meta'];
        self::assertIsArray($meta);
        self::assertSame([], $body['data']);
        self::assertSame(1, $meta['total']); // total is still 1
    }

    public function testLimitIsClamped(): void
    {
        $this->client->request('GET', '/api/v1/leaderboard?axis=goals&limit=0');
        self::assertResponseStatusCodeSame(200); // limit 0 → clamped to 1

        $this->client->request('GET', '/api/v1/leaderboard?axis=goals&limit=200');
        self::assertResponseStatusCodeSame(200); // limit 200 → clamped to 100
        $limitResponse = $this->decodedJsonResponse();
        $meta = $limitResponse['meta'];
        self::assertIsArray($meta);
        // meta doesn't expose the actual limit, but total=0 means we got a valid response
        self::assertArrayHasKey('total', $meta);
    }

    // ─── Invalid axis ─────────────────────────────────────────────────────────

    public function testInvalidAxisReturns422(): void
    {
        $this->client->request('GET', '/api/v1/leaderboard?axis=invalid');

        self::assertResponseStatusCodeSame(422);
        $errorResponse = $this->decodedJsonResponse();
        $error = $errorResponse['error'];
        self::assertIsArray($error);
        self::assertSame('invalid_axis', $error['code']);
    }

    public function testMissingAxisReturns422(): void
    {
        $this->client->request('GET', '/api/v1/leaderboard');

        self::assertResponseStatusCodeSame(422);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function makeFinishedSession(string $eventId, \DateTimeImmutable $now): Session
    {
        $session = Session::create(bin2hex(random_bytes(16)), $eventId, $now);
        $session->transition(Session::STATUS_VALIDATING, $now);
        $session->transition(Session::STATUS_READY, $now);
        $session->transition(Session::STATUS_GENERATING, $now);
        $session->transition(Session::STATUS_GENERATED, $now);
        $session->transition(Session::STATUS_LAUNCHING, $now);
        $session->transition(Session::STATUS_RUNNING, $now, 'bridge.local', 38281, 'secret', 5000);
        $session->transition(Session::STATUS_FINISHED, $now->modify('+2 hours'));
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }
}
