<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;

final class PlayerSessionConnectionTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testUnauthenticatedGets401(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/registrations/nonexistent/session-connection');
        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownRegistrationGets404(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/registrations/nonexistent/session-connection');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRegistrationOwnedByOtherUserGets404(): void
    {
        $owner = $this->createUser('owner@example.org');
        $other = $this->createUser('other@example.org');
        $registration = $this->createConfirmedRegistration($owner->getId(), 'evt-001');

        $this->loginAs($other);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/session-connection', $registration->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testPendingRegistrationGets404(): void
    {
        $user = $this->createUser('user@example.org');
        $registration = $this->createPendingRegistration($user->getId(), 'evt-001');

        $this->loginAs($user);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/session-connection', $registration->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testCancelledRegistrationGets404(): void
    {
        $user = $this->createUser('user@example.org');
        $registration = $this->createCancelledRegistration($user->getId(), 'evt-001');

        $this->loginAs($user);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/session-connection', $registration->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testConfirmedRegistrationWithNoSessionReturnsNullSession(): void
    {
        $user = $this->createUser('user@example.org');
        $registration = $this->createConfirmedRegistration($user->getId(), 'evt-001');

        $this->loginAs($user);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/session-connection', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $responseData = $this->decodedJsonResponse();
        $data = $responseData['data'];
        self::assertIsArray($data);
        self::assertNull($data['session']);
        self::assertSame([], $data['slots']);
    }

    public function testConfirmedRegistrationWithRunningSessionReturnsConnectionData(): void
    {
        $user = $this->createUser('user@example.org');
        $registration = $this->createConfirmedRegistration($user->getId(), 'evt-001');
        $game = $this->createGame('Hollow Knight', 'hollow-knight');

        $session = $this->createRunningSession('evt-001', '10.0.0.1', 38281, 'archipelago');
        $this->createSessionSlot($session->getId(), $registration->getId(), $game->getId(), 'Jean_HK1', 0);

        $this->loginAs($user);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/session-connection', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $responseData = $this->decodedJsonResponse();
        $data = $responseData['data'];
        self::assertIsArray($data);

        $session = $data['session'];
        self::assertIsArray($session);
        self::assertSame('running', $session['status']);
        self::assertSame('10.0.0.1', $session['host']);
        self::assertSame(38281, $session['port']);
        self::assertSame('archipelago', $session['password']);

        $slots = $data['slots'];
        self::assertIsArray($slots);
        self::assertCount(1, $slots);
        $firstSlot = $slots[0];
        self::assertIsArray($firstSlot);
        self::assertSame('Jean_HK1', $firstSlot['slotName']);
        self::assertSame(0, $firstSlot['slotOrder']);
        self::assertSame($game->getId(), $firstSlot['gameId']);
        self::assertSame('Hollow Knight', $firstSlot['gameName']);
    }

    public function testPlayerOnlySeesOwnSlots(): void
    {
        $playerA = $this->createUser('player-a@example.org');
        $playerB = $this->createUser('player-b@example.org');
        $regA = $this->createConfirmedRegistration($playerA->getId(), 'evt-001');
        $regB = $this->createConfirmedRegistration($playerB->getId(), 'evt-001');
        $game = $this->createGame('A Link to the Past', 'alttp');

        $session = $this->createRunningSession('evt-001', '192.168.1.1', 38281, 'pass');
        $this->createSessionSlot($session->getId(), $regA->getId(), $game->getId(), 'Alice_ALttP1', 0);
        $this->createSessionSlot($session->getId(), $regB->getId(), $game->getId(), 'Bob_ALttP1', 1);

        $this->loginAs($playerA);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/session-connection', $regA->getId()));
        self::assertResponseStatusCodeSame(200);

        $responseData = $this->decodedJsonResponse();
        $data = $responseData['data'];
        self::assertIsArray($data);
        $slots = $data['slots'];
        self::assertIsArray($slots);
        self::assertCount(1, $slots);
        $firstSlot = $slots[0];
        self::assertIsArray($firstSlot);
        self::assertSame('Alice_ALttP1', $firstSlot['slotName']);
    }

    public function testPlayerOnlySeesSlotsForLatestSession(): void
    {
        $player = $this->createUser('player@example.org');
        $registration = $this->createConfirmedRegistration($player->getId(), 'evt-001');
        $game = $this->createGame('Hollow Knight', 'hollow-knight');

        $oldSession = $this->createRunningSession('evt-001', '10.0.0.1', 38281, 'old-pass', new \DateTimeImmutable('2026-05-02T10:00:00+00:00'));
        $oldSession->transition(Session::STATUS_STOPPED, new \DateTimeImmutable('2026-05-02T11:00:00+00:00'));
        $this->createSessionSlot($oldSession->getId(), $registration->getId(), $game->getId(), 'Jean_HK_old', 0);

        $latestSession = $this->createRunningSession('evt-001', '10.0.0.2', 38282, 'new-pass', new \DateTimeImmutable('2026-05-02T12:00:00+00:00'));
        $this->createSessionSlot($latestSession->getId(), $registration->getId(), $game->getId(), 'Jean_HK_new', 0);

        $this->entityManager->flush();

        $this->loginAs($player);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/session-connection', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $responseData = $this->decodedJsonResponse();
        $data = $responseData['data'];
        self::assertIsArray($data);
        $session = $data['session'];
        self::assertIsArray($session);
        self::assertSame($latestSession->getId(), $session['id']);
        self::assertSame('10.0.0.2', $session['host']);
        $slots = $data['slots'];
        self::assertIsArray($slots);
        self::assertCount(1, $slots);
        $firstSlot = $slots[0];
        self::assertIsArray($firstSlot);
        self::assertSame('Jean_HK_new', $firstSlot['slotName']);
    }

    public function testStoppedSessionKeepsConnectionDataForHistory(): void
    {
        $user = $this->createUser('user@example.org');
        $registration = $this->createConfirmedRegistration($user->getId(), 'evt-001');
        $game = $this->createGame('Hollow Knight', 'hollow-knight');

        $session = $this->createRunningSession('evt-001', '10.0.0.1', 38281, 'archipelago');
        $session->transition(Session::STATUS_STOPPED, new \DateTimeImmutable());
        $this->createSessionSlot($session->getId(), $registration->getId(), $game->getId(), 'Jean_HK1', 0);
        $this->entityManager->flush();

        $this->loginAs($user);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/session-connection', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $responseData = $this->decodedJsonResponse();
        $data = $responseData['data'];
        self::assertIsArray($data);
        $session = $data['session'];
        self::assertIsArray($session);
        self::assertSame('stopped', $session['status']);
        self::assertSame('10.0.0.1', $session['host']);
        self::assertSame(38281, $session['port']);
        self::assertSame('archipelago', $session['password']);
    }

    public function testPlayerCannotAccessOtherPlayersRegistration(): void
    {
        $playerA = $this->createUser('player-a@example.org');
        $playerB = $this->createUser('player-b@example.org');
        $regB = $this->createConfirmedRegistration($playerB->getId(), 'evt-001');

        $this->loginAs($playerA);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/session-connection', $regB->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testSessionInDraftStatusIsReturnedWithoutConnectionDetails(): void
    {
        $user = $this->createUser('user@example.org');
        $registration = $this->createConfirmedRegistration($user->getId(), 'evt-001');
        $game = $this->createGame('Hollow Knight', 'hollow-knight');

        $session = $this->createDraftSession('evt-001');
        $this->createSessionSlot($session->getId(), $registration->getId(), $game->getId(), 'Jean_HK1', 0);

        $this->loginAs($user);
        $this->client->jsonRequest('GET', sprintf('/api/v1/registrations/%s/session-connection', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $responseData = $this->decodedJsonResponse();
        $data = $responseData['data'];
        self::assertIsArray($data);
        $session = $data['session'];
        self::assertIsArray($session);
        self::assertSame('draft', $session['status']);
        self::assertNull($session['host']);
        self::assertNull($session['port']);
        self::assertNull($session['password']);
        $slots = $data['slots'];
        self::assertIsArray($slots);
        self::assertCount(1, $slots);
    }

    private function createConfirmedRegistration(string $userId, string $eventId): Registration
    {
        $now = new \DateTimeImmutable('2026-05-02T10:00:00+00:00');
        $registration = $this->createRegistration($eventId, $userId);
        $registration->confirm($now);
        $this->entityManager->flush();

        return $registration;
    }

    private function createPendingRegistration(string $userId, string $eventId): Registration
    {
        return $this->createRegistration($eventId, $userId);
    }

    private function createCancelledRegistration(string $userId, string $eventId): Registration
    {
        return $this->createRegistration($eventId, $userId, Registration::STATUS_CANCELLED);
    }

    private function createDraftSession(string $eventId): Session
    {
        $session = Session::create(bin2hex(random_bytes(16)), $eventId, new \DateTimeImmutable());
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    private function createRunningSession(
        string $eventId,
        string $host,
        int $port,
        string $password,
        ?\DateTimeImmutable $now = null,
    ): Session {
        $now ??= new \DateTimeImmutable();
        $session = Session::create(bin2hex(random_bytes(16)), $eventId, $now);

        foreach ([Session::STATUS_VALIDATING, Session::STATUS_READY, Session::STATUS_GENERATING, Session::STATUS_GENERATED, Session::STATUS_LAUNCHING] as $status) {
            $session->transition($status, $now);
        }
        $session->transition(Session::STATUS_RUNNING, $now, $host, $port, $password);

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    private function createSessionSlot(
        string $sessionId,
        string $registrationId,
        string $gameId,
        string $slotName,
        int $slotOrder,
    ): SessionSlot {
        $slot = SessionSlot::create(
            bin2hex(random_bytes(16)),
            $sessionId,
            $registrationId,
            $gameId,
            $slotName,
            $slotOrder,
        );
        $this->entityManager->persist($slot);
        $this->entityManager->flush();

        return $slot;
    }
}
