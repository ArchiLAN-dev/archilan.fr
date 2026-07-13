<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Entity\Event;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionRecap;
use App\Sessions\Domain\Entity\SessionSlot;

final class SessionRecapEndpointTest extends FunctionalTestCase
{
    public function testFinishedPublicSessionReturns200WithGraphPodiumAndSuperlatives(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent('ArchiLAN Recap', $now, $now->modify('+2 days'), published: true);
        // A VOD can only be attached once the event is completed (story 3.8).
        $event->transitionTo(Event::STATUS_IN_PROGRESS, $now);
        $event->transitionTo(Event::STATUS_COMPLETED, $now->modify('+2 days'));
        $event->attachRecap('https://www.twitch.tv/videos/123456789', null, $now->modify('+2 days'));
        $this->entityManager->flush();

        $game = $this->createGame('Multi', 'multi');
        $session = $this->createFinishedSession($event->getId(), $now);
        $startedAt = $session->getStartedAt();
        self::assertNotNull($startedAt);

        $userA = $this->createUser('a@example.org', displayName: 'Alice');
        $userB = $this->createUser('b@example.org', displayName: 'Bob');
        $regA = $this->createRegistration($event->getId(), $userA->getId());
        $regB = $this->createRegistration($event->getId(), $userB->getId());

        $slotA = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regA->getId(), $game->getId(), 'Player1', 0, 'slot-p1');
        $slotA->setGoalReachedAt($startedAt->modify('+100 seconds'));
        $this->entityManager->persist($slotA);
        $slotB = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regB->getId(), $game->getId(), 'Player2', 1, 'slot-p2');
        $slotB->setGoalReachedAt($startedAt->modify('+200 seconds'));
        $this->entityManager->persist($slotB);

        $this->persistProjection($session->getId());
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/api/v1/parties/%s/recap', $session->getId()));

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);

        self::assertSame($session->getId(), $data['sessionId']);
        self::assertSame('ArchiLAN Recap', $data['eventName']);
        self::assertSame('https://www.twitch.tv/videos/123456789', $data['vodUrl']);
        self::assertIsString($data['generatedAt']);

        // Podium comes from the reused RunResultsQuery (Alice first at 100s).
        $podium = $data['podium'];
        self::assertIsArray($podium);
        self::assertCount(2, $podium);
        $podiumFirst = $podium[0];
        self::assertIsArray($podiumFirst);
        self::assertSame('slot-p1', $podiumFirst['slotId']);
        self::assertSame('Alice', $podiumFirst['playerName']);

        // Graph + superlatives come from the persisted projection.
        $graph = $data['graph'];
        self::assertIsArray($graph);
        $nodes = $graph['nodes'];
        self::assertIsArray($nodes);
        self::assertCount(2, $nodes);
        $edges = $graph['edges'];
        self::assertIsArray($edges);
        self::assertCount(1, $edges);
        $edge = $edges[0];
        self::assertIsArray($edge);
        self::assertSame('slot-p1', $edge['fromSlotId']);
        self::assertSame(32, $edge['count']);

        $superlatives = $data['superlatives'];
        self::assertIsArray($superlatives);
        $topSuperlative = $superlatives[0];
        self::assertIsArray($topSuperlative);
        self::assertSame('most_generous', $topSuperlative['key']);
        self::assertSame('slot-p2', $topSuperlative['slotId']);
    }

    public function testNonFinishedSessionReturns404(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent('Live event', $now, $now->modify('+1 day'));

        $session = Session::create(bin2hex(random_bytes(16)), $event->getId(), $now);
        $session->transition(Session::STATUS_VALIDATING, $now);
        $session->transition(Session::STATUS_READY, $now);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/api/v1/parties/%s/recap', $session->getId()));

        self::assertResponseStatusCodeSame(404);
        $error = $this->decodedJsonResponse()['error'];
        self::assertIsArray($error);
        self::assertSame('recap_not_found', $error['code']);
    }

    public function testPersonalOrPrivateRunReturns404EvenWithAProjection(): void
    {
        // eventId points at no Event row (personal/weekly run): recap is never public.
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $session = $this->createFinishedSession('run-'.bin2hex(random_bytes(8)), $now);
        $this->persistProjection($session->getId());
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/api/v1/parties/%s/recap', $session->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonPublicEventReturns404EvenWithAProjection(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent('Private event', $now, $now->modify('+1 day'), isPublic: false);
        $session = $this->createFinishedSession($event->getId(), $now);
        $this->persistProjection($session->getId());
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/api/v1/parties/%s/recap', $session->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testFinishedPublicSessionWithoutProjectionReturns404(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent('No recap yet', $now, $now->modify('+1 day'));
        $session = $this->createFinishedSession($event->getId(), $now);
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/api/v1/parties/%s/recap', $session->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    private function persistProjection(string $sessionId): void
    {
        $recap = new SessionRecap(
            $sessionId,
            new \DateTimeImmutable('2026-06-01T00:00:00+00:00'),
            [
                ['slotId' => 'slot-p1', 'slotName' => 'Player1', 'game' => "Luigi's Mansion"],
                ['slotId' => 'slot-p2', 'slotName' => 'Player2', 'game' => 'Super Mario 64'],
            ],
            [['fromSlotId' => 'slot-p1', 'toSlotId' => 'slot-p2', 'count' => 32]],
            [['slotId' => 'slot-p1', 'count' => 103]],
            [['key' => 'most_generous', 'label' => 'Le Parrain', 'slotId' => 'slot-p2', 'value' => 60]],
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
