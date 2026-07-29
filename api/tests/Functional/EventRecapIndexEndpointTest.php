<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionRecap;
use App\Sessions\Domain\Entity\SessionSlot;

final class EventRecapIndexEndpointTest extends FunctionalTestCase
{
    public function testListsOnlyFinishedSessionsWithAProjectionNewestFirst(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent('ArchiLAN Index', $now, $now->modify('+2 days'), published: true);
        $game = $this->createGame('Multi', 'multi');

        $userA = $this->createUser('a@example.org', displayName: 'Alice');
        $userB = $this->createUser('b@example.org', displayName: 'Bob');
        $regA = $this->createRegistration($event->getId(), $userA->getId());
        $regB = $this->createRegistration($event->getId(), $userB->getId());

        // Eligible session #1: finished at +1h, projection persisted, Alice wins at +100s.
        $first = $this->createFinishedSession($event->getId(), $now);
        $firstStartedAt = $first->getStartedAt();
        self::assertNotNull($firstStartedAt);
        $slotA = SessionSlot::create(bin2hex(random_bytes(16)), $first->getId(), $regA->getId(), $game->getId(), 'Player1', 0, 'slot-p1');
        $slotA->recordGoal($firstStartedAt->modify('+100 seconds'));
        $this->entityManager->persist($slotA);
        $slotB = SessionSlot::create(bin2hex(random_bytes(16)), $first->getId(), $regB->getId(), $game->getId(), 'Player2', 1, 'slot-p2');
        $this->entityManager->persist($slotB);
        $this->persistProjection($first->getId());

        // Eligible session #2: finished later (+1 day) - must come first in the index.
        $second = $this->createFinishedSession($event->getId(), $now->modify('+1 day'));
        $this->persistProjection($second->getId());

        // Excluded: finished but no projection (pre-32.1 session).
        $this->createFinishedSession($event->getId(), $now->modify('+2 hours'));

        // Excluded: not finished, even with a projection.
        $running = Session::create(bin2hex(random_bytes(16)), $event->getId(), $now);
        $running->transition(Session::STATUS_VALIDATING, $now);
        $running->transition(Session::STATUS_READY, $now);
        $this->entityManager->persist($running);
        $this->persistProjection($running->getId());

        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/api/v1/events/%s/parties', $event->getId()));

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertCount(2, $data);

        $newest = $data[0];
        self::assertIsArray($newest);
        self::assertSame($second->getId(), $newest['sessionId']);

        $entry = $data[1];
        self::assertIsArray($entry);
        self::assertSame($first->getId(), $entry['sessionId']);
        self::assertIsString($entry['startedAt']);
        self::assertIsString($entry['finishedAt']);
        self::assertSame(3600, $entry['durationSeconds']);
        self::assertSame(2, $entry['playerCount']);
        $winner = $entry['winner'];
        self::assertIsArray($winner);
        self::assertSame('Alice', $winner['playerName']);
        self::assertSame('Multi', $winner['game']);

        // No goal reached in the second session - winner stays null.
        self::assertNull($newest['winner']);
    }

    public function testPublicEventWithoutEligibleSessionsReturnsEmptyList(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent('Empty index', $now, $now->modify('+1 day'), published: true);
        $this->createFinishedSession($event->getId(), $now); // no projection
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/api/v1/events/%s/parties', $event->getId()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->decodedJsonResponse()['data']);
    }

    public function testUnknownEventReturns404(): void
    {
        $this->client->request('GET', '/api/v1/events/nope/parties');

        self::assertResponseStatusCodeSame(404);
        $error = $this->decodedJsonResponse()['error'];
        self::assertIsArray($error);
        self::assertSame('not_found', $error['code']);
    }

    public function testNonPublicEventReturns404EvenWithEligibleSessions(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent('Private event', $now, $now->modify('+1 day'), isPublic: false);
        $session = $this->createFinishedSession($event->getId(), $now);
        $this->persistProjection($session->getId());
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/api/v1/events/%s/parties', $event->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    private function persistProjection(string $sessionId): void
    {
        $recap = new SessionRecap(
            $sessionId,
            new \DateTimeImmutable('2026-06-01T00:00:00+00:00'),
            [['slotId' => 'slot-p1', 'slotName' => 'Player1', 'game' => 'Multi']],
            [],
            [],
            [],
        );
        $this->entityManager->persist($recap);
    }

    private function createFinishedSession(string $eventId, \DateTimeImmutable $now): Session
    {
        $session = Session::create(bin2hex(random_bytes(16)), $eventId, $now);
        $session->transition(Session::STATUS_VALIDATING, $now);
        $session->transition(Session::STATUS_READY, $now);
        $session->transition(Session::STATUS_GENERATING, $now);
        $session->transition(Session::STATUS_GENERATED, $now);
        $session->transition(Session::STATUS_LAUNCHING, $now);
        $session->transition(Session::STATUS_RUNNING, $now, 'bridge.local', 38281, 'secret', 5000);
        $session->transition(Session::STATUS_FINISHED, $now->modify('+1 hour'));
        $this->entityManager->persist($session);

        return $session;
    }
}
