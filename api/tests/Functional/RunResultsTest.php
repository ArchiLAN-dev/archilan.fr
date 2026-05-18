<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameCatalogSync;
use App\Identity\Domain\User;
use App\PersonalRuns\Domain\Run;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use Doctrine\ORM\Tools\SchemaTool;

final class RunResultsTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Event::class),
            $this->entityManager->getClassMetadata(Registration::class),
            $this->entityManager->getClassMetadata(Session::class),
            $this->entityManager->getClassMetadata(SessionSlot::class),
            $this->entityManager->getClassMetadata(Game::class),
            $this->entityManager->getClassMetadata(GameCatalogSync::class),
            $this->entityManager->getClassMetadata(Run::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testFinishedSessionReturns200WithCorrectPayloadAndSlotOrdering(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');

        $event = $this->createEvent('ArchiLAN 2026', $now, $now->modify('+2 days'));
        $game = $this->createGame('Hollow Knight', 'hollow-knight');

        $userA = $this->createUser('alice@example.org', displayName: 'Alice');
        $userB = $this->createUser('bob@example.org', displayName: 'Bob');
        $userC = $this->createUser('carol@example.org', displayName: 'Carol');
        $userD = $this->createUser('dave@example.org', displayName: 'Dave');

        $regA = $this->createRegistration($event->getId(), $userA->getId());
        $regB = $this->createRegistration($event->getId(), $userB->getId());
        $regC = $this->createRegistration($event->getId(), $userC->getId());
        $regD = $this->createRegistration($event->getId(), $userD->getId());

        $session = $this->createFinishedSession($event->getId(), $now);

        $startedAt = $session->getStartedAt();
        self::assertNotNull($startedAt);

        // Slot A: goal reached at +200s → completionSeconds=200
        $slotA = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regA->getId(), $game->getId(), 'Alice', 0, 'slot-1');
        $slotA->setGoalReachedAt($startedAt->modify('+200 seconds'));
        $slotA->setChecksDone(50);
        $slotA->setItemsReceived(30);
        $this->entityManager->persist($slotA);

        // Slot B: goal reached at +100s → completionSeconds=100 (should appear first)
        $slotB = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regB->getId(), $game->getId(), 'Bob', 1, 'slot-2');
        $slotB->setGoalReachedAt($startedAt->modify('+100 seconds'));
        $slotB->setChecksDone(40);
        $slotB->setItemsReceived(20);
        $this->entityManager->persist($slotB);

        // Slot C: incomplete (no goal, not released)
        $slotC = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regC->getId(), $game->getId(), 'Carol', 2, 'slot-3');
        $slotC->setChecksDone(10);
        $slotC->setItemsReceived(5);
        $this->entityManager->persist($slotC);

        // Slot D: invalidated (wasReleased=true, no goal)
        $slotD = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $regD->getId(), $game->getId(), 'Dave', 3, 'slot-4');
        $slotD->markAsReleased();
        $this->entityManager->persist($slotD);

        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/api/v1/runs/%s/results', $session->getId()));

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodedJsonResponse();
        $data = $body['data'];
        self::assertIsArray($data);

        self::assertSame($session->getId(), $data['sessionId']);
        self::assertSame('ArchiLAN 2026', $data['eventName']);
        self::assertIsString($data['startedAt']);
        self::assertIsString($data['finishedAt']);
        self::assertIsInt($data['durationSeconds']);
        self::assertGreaterThan(0, $data['durationSeconds']);

        $slots = $data['slots'];
        self::assertIsArray($slots);
        self::assertCount(4, $slots);

        // Expected order: Bob (100s), Alice (200s), Carol (incomplete), Dave (invalidated)
        $slot0 = $slots[0];
        self::assertIsArray($slot0);
        self::assertSame('slot-2', $slot0['slotId']);
        self::assertSame('Bob', $slot0['playerName']);
        self::assertSame('Hollow Knight', $slot0['game']);
        self::assertSame(100, $slot0['completionSeconds']);
        self::assertIsString($slot0['goalReachedAt']);
        self::assertFalse($slot0['wasReleased']);
        self::assertFalse($slot0['isInvalidated']);

        $slot1 = $slots[1];
        self::assertIsArray($slot1);
        self::assertSame('slot-1', $slot1['slotId']);
        self::assertSame('Alice', $slot1['playerName']);
        self::assertSame(200, $slot1['completionSeconds']);
        self::assertFalse($slot1['isInvalidated']);

        $slot2 = $slots[2];
        self::assertIsArray($slot2);
        self::assertSame('slot-3', $slot2['slotId']);
        self::assertSame('Carol', $slot2['playerName']);
        self::assertNull($slot2['completionSeconds']);
        self::assertNull($slot2['goalReachedAt']);
        self::assertFalse($slot2['isInvalidated']);

        $slot3 = $slots[3];
        self::assertIsArray($slot3);
        self::assertSame('slot-4', $slot3['slotId']);
        self::assertSame('Dave', $slot3['playerName']);
        self::assertNull($slot3['completionSeconds']);
        self::assertTrue($slot3['wasReleased']);
        self::assertTrue($slot3['isInvalidated']);
    }

    public function testInvalidatedSlotHasIsInvalidatedTrue(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T12:00:00+00:00');

        $event = $this->createEvent('LAN Test', $now, $now->modify('+1 day'), 10);
        $game = $this->createGame('A Link to the Past', 'alttp');

        $user = $this->createUser('eve@example.org', displayName: 'Eve');
        $reg = $this->createRegistration($event->getId(), $user->getId());

        $session = $this->createFinishedSession($event->getId(), $now);

        $slot = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $reg->getId(), $game->getId(), 'Eve', 0);
        $slot->markAsReleased();
        $this->entityManager->persist($slot);
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/api/v1/runs/%s/results', $session->getId()));

        self::assertResponseStatusCodeSame(200);
        $responseBody = $this->decodedJsonResponse();
        $responseData = $responseBody['data'];
        self::assertIsArray($responseData);
        $slots = $responseData['slots'];
        self::assertIsArray($slots);
        self::assertCount(1, $slots);
        $slot = $slots[0];
        self::assertIsArray($slot);
        self::assertTrue($slot['wasReleased']);
        self::assertTrue($slot['isInvalidated']);
        self::assertNull($slot['completionSeconds']);
    }

    public function testNonFinishedSessionReturns404(): void
    {
        $now = new \DateTimeImmutable();
        $session = $this->createRunningSession('running-session', 'evt-nf-1', $now);

        $this->client->request('GET', sprintf('/api/v1/runs/%s/results', $session->getId()));

        self::assertResponseStatusCodeSame(404);
        $body = $this->decodedJsonResponse();
        $error = $body['error'];
        self::assertIsArray($error);
        self::assertSame('run_not_found_or_not_finished', $error['code']);
    }

    public function testNonExistentSessionReturns404(): void
    {
        $this->client->request('GET', '/api/v1/runs/does-not-exist/results');

        self::assertResponseStatusCodeSame(404);
    }

    public function testPersonalRunSessionReturnsEventNameFromPersonalRun(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');

        $game = $this->createGame('Super Metroid', 'super-metroid');

        $user = $this->createUser('frank@example.org', displayName: 'Frank');

        // Run::create() uses bin2hex(random_bytes(16)) for its id
        $pr = Run::create($user->getId(), 'Mon run perso', $now);
        $this->entityManager->persist($pr);
        $this->entityManager->flush();

        // Session eventId = Run id (no Event row exists)
        $session = $this->createFinishedSession($pr->getId(), $now);

        // For personal runs, registrationId IS the userId
        $slot = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $user->getId(), $game->getId(), 'Frank', 0, 'slot-pr-1');
        $slot->setGoalReachedAt($session->getStartedAt()?->modify('+300 seconds'));
        $slot->setChecksDone(25);
        $slot->setItemsReceived(15);
        $this->entityManager->persist($slot);
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/api/v1/runs/%s/results', $session->getId()));

        self::assertResponseStatusCodeSame(200);
        $prResponse = $this->decodedJsonResponse();
        $data = $prResponse['data'];
        self::assertIsArray($data);

        self::assertSame($session->getId(), $data['sessionId']);
        self::assertSame('Mon run perso', $data['eventName']);
        $prSlots = $data['slots'];
        self::assertIsArray($prSlots);
        self::assertCount(1, $prSlots);

        $s = $prSlots[0];
        self::assertIsArray($s);
        self::assertSame('slot-pr-1', $s['slotId']);
        self::assertSame('Frank', $s['playerName']);
        self::assertSame('Super Metroid', $s['game']);
        self::assertSame(300, $s['completionSeconds']);
        self::assertFalse($s['wasReleased']);
        self::assertFalse($s['isInvalidated']);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function createRunningSession(string $id, string $eventId, \DateTimeImmutable $now): Session
    {
        $session = Session::create($id, $eventId, $now);
        $session->transition(Session::STATUS_VALIDATING, $now);
        $session->transition(Session::STATUS_READY, $now);
        $session->transition(Session::STATUS_GENERATING, $now);
        $session->transition(Session::STATUS_GENERATED, $now);
        $session->transition(Session::STATUS_LAUNCHING, $now);
        $session->transition(Session::STATUS_RUNNING, $now, 'bridge.local', 38281, 'secret', 5000);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
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
        $this->entityManager->flush();

        return $session;
    }
}
