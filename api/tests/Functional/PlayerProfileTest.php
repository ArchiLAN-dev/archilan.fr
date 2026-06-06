<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;

final class PlayerProfileTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testProfileReturns200WithCorrectStats(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');

        $user = $this->createUser('jean@example.org', ['ROLE_USER'], 'Jean', 'jean');

        $event = $this->createEvent('ArchiLAN 2026', $now, $now->modify('+2 days'));
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $reg = $this->createRegistration($event->getId(), $user->getId());

        $session = $this->makeFinishedSession($event->getId(), $now);

        $startedAt = $session->getStartedAt();
        self::assertNotNull($startedAt);

        // Slot 1: goal reached (counts in goalCompletions, checks, items)
        $slot1 = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $reg->getId(), $game->getId(), 'Jean', 0);
        $slot1->setGoalReachedAt($startedAt->modify('+100 seconds'));
        $slot1->setChecksDone(50);
        $slot1->setItemsReceived(30);
        $this->entityManager->persist($slot1);

        // Slot 2: invalidated (was_released, no goal) - excluded from goalCompletions, checks, items
        $slot2 = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $reg->getId(), $game->getId(), 'Jean2', 1);
        $slot2->setChecksDone(10);
        $slot2->setItemsReceived(5);
        $slot2->markAsReleased();
        $this->entityManager->persist($slot2);

        $this->entityManager->flush();

        $this->client->request('GET', '/api/v1/players/jean');

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodedJsonResponse();
        $data = $body['data'];
        self::assertIsArray($data);

        self::assertSame('jean', $data['slug']);
        self::assertSame('Jean', $data['displayName']);
        self::assertIsString($data['joinedAt']);

        $stats = $data['stats'];
        self::assertIsArray($stats);
        self::assertSame(1, $stats['runsParticipated']); // 1 distinct finished session
        self::assertSame(1, $stats['goalCompletions']); // only slot1 has goal
        self::assertEqualsWithDelta(1.0, $stats['goalCompletionRate'], 0.001); // 1/1
        self::assertSame(50, $stats['totalChecksDone']); // slot2 excluded (invalidated)
        self::assertSame(30, $stats['totalItemsReceived']); // slot2 excluded
    }

    public function testInvalidatedSlotExcludedFromGoalRate(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');

        $user = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice', 'alice');

        $event = $this->createEvent('LAN Test', $now, $now->modify('+1 day'), 10);
        $game = $this->createGame('ALttP', 'alttp');
        $reg = $this->createRegistration($event->getId(), $user->getId());

        // Session 1: goal reached
        $session1 = $this->makeFinishedSession($event->getId(), $now);
        $startedAt1 = $session1->getStartedAt();
        self::assertNotNull($startedAt1);
        $s1slot = SessionSlot::create(bin2hex(random_bytes(16)), $session1->getId(), $reg->getId(), $game->getId(), 'Alice', 0);
        $s1slot->setGoalReachedAt($startedAt1->modify('+60 seconds'));
        $s1slot->setChecksDone(20);
        $s1slot->setItemsReceived(10);
        $this->entityManager->persist($s1slot);

        // Session 2: invalidated (no goal)
        $session2 = $this->makeFinishedSession($event->getId(), $now->modify('+1 day'));
        $s2slot = SessionSlot::create(bin2hex(random_bytes(16)), $session2->getId(), $reg->getId(), $game->getId(), 'Alice', 0);
        $s2slot->setChecksDone(5);
        $s2slot->setItemsReceived(3);
        $s2slot->markAsReleased();
        $this->entityManager->persist($s2slot);

        $this->entityManager->flush();

        $this->client->request('GET', '/api/v1/players/alice');

        self::assertResponseStatusCodeSame(200);
        $aliceResponse = $this->decodedJsonResponse();
        $aliceData = $aliceResponse['data'];
        self::assertIsArray($aliceData);
        $stats = $aliceData['stats'];
        self::assertIsArray($stats);
        self::assertSame(2, $stats['runsParticipated']); // 2 sessions regardless of invalidation
        self::assertSame(1, $stats['goalCompletions']); // only session1
        self::assertEqualsWithDelta(0.5, $stats['goalCompletionRate'], 0.001); // 1/2
        self::assertSame(20, $stats['totalChecksDone']); // only session1 slot (session2 is invalidated)
        self::assertSame(10, $stats['totalItemsReceived']);
    }

    public function testPlayerWithNoFinishedRunsHasEmptyHistory(): void
    {
        $now = new \DateTimeImmutable();

        $user = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob', 'bob');

        $this->client->request('GET', '/api/v1/players/bob');
        self::assertResponseStatusCodeSame(200);
        $bobResponse = $this->decodedJsonResponse();
        $bobData = $bobResponse['data'];
        self::assertIsArray($bobData);
        $stats = $bobData['stats'];
        self::assertIsArray($stats);
        self::assertSame(0, $stats['runsParticipated']);
        self::assertSame(0, $stats['goalCompletions']);
        self::assertEqualsWithDelta(0.0, $stats['goalCompletionRate'], 0.001);

        $this->client->request('GET', '/api/v1/players/bob/history');
        self::assertResponseStatusCodeSame(200);
        $body = $this->decodedJsonResponse();
        $bodyMeta = $body['meta'];
        self::assertIsArray($bodyMeta);
        self::assertSame([], $body['data']);
        self::assertSame(0, $bodyMeta['total']);
    }

    public function testMultipleSlotGoalsInSameSessionCountAsOneGoalCompletion(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');

        $user = $this->createUser('diana@example.org', ['ROLE_USER'], 'Diana', 'diana');

        $event = $this->createEvent('LAN Multi', $now, $now->modify('+1 day'), 20);
        $game = $this->createGame('SMW', 'smw');
        $reg = $this->createRegistration($event->getId(), $user->getId());

        $session = $this->makeFinishedSession($event->getId(), $now);
        $startedAt = $session->getStartedAt();
        self::assertNotNull($startedAt);

        // Two slots in the same session, both with goals
        $slot1 = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $reg->getId(), $game->getId(), 'Diana', 0);
        $slot1->setGoalReachedAt($startedAt->modify('+60 seconds'));
        $this->entityManager->persist($slot1);

        $slot2 = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $reg->getId(), $game->getId(), 'Diana2', 1);
        $slot2->setGoalReachedAt($startedAt->modify('+120 seconds'));
        $this->entityManager->persist($slot2);

        $this->entityManager->flush();

        $this->client->request('GET', '/api/v1/players/diana');

        self::assertResponseStatusCodeSame(200);
        $dianaResponse = $this->decodedJsonResponse();
        $dianaData = $dianaResponse['data'];
        self::assertIsArray($dianaData);
        $stats = $dianaData['stats'];
        self::assertIsArray($stats);
        self::assertSame(1, $stats['runsParticipated']);
        self::assertSame(1, $stats['goalCompletions']); // 2 goals but 1 session → rate stays ≤ 1.0
        self::assertEqualsWithDelta(1.0, $stats['goalCompletionRate'], 0.001);
    }

    public function testNonExistentSlugReturns404OnBothEndpoints(): void
    {
        $this->client->request('GET', '/api/v1/players/does-not-exist');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/players/does-not-exist/history');
        self::assertResponseStatusCodeSame(404);
    }

    public function testHistoryReturnsPaginatedRunsOrderedByFinishedAtDesc(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');

        $user = $this->createUser('carol@example.org', ['ROLE_USER'], 'Carol', 'carol');

        $event = $this->createEvent('LAN', $now, $now->modify('+3 days'), 20);
        $game = $this->createGame('Game', 'game-slug');
        $reg = $this->createRegistration($event->getId(), $user->getId());

        // Create 3 sessions finished at different times
        $session1 = $this->makeFinishedSession($event->getId(), $now);
        $session2 = $this->makeFinishedSession($event->getId(), $now->modify('+1 day'));
        $session3 = $this->makeFinishedSession($event->getId(), $now->modify('+2 days'));

        foreach ([$session1, $session2, $session3] as $session) {
            $slot = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $reg->getId(), $game->getId(), 'Carol', 0);
            $slot->setChecksDone(10);
            $this->entityManager->persist($slot);
        }
        $this->entityManager->flush();

        $this->client->request('GET', '/api/v1/players/carol/history?page=1&limit=2');

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodedJsonResponse();
        $historyMeta = $body['meta'];
        self::assertIsArray($historyMeta);
        $historyItems = $body['data'];
        self::assertIsArray($historyItems);
        self::assertCount(2, $historyItems);
        self::assertSame(3, $historyMeta['total']);
        self::assertSame(1, $historyMeta['page']);
        self::assertSame(2, $historyMeta['limit']);

        // Most recent first (session3 finished at +2 days, session2 at +1 day)
        $item0 = $historyItems[0];
        self::assertIsArray($item0);
        $item1 = $historyItems[1];
        self::assertIsArray($item1);
        self::assertGreaterThan($item1['finishedAt'], $item0['finishedAt']);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

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
