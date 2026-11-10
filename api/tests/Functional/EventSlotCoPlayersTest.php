<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Registrations\Domain\Entity\Registration;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;

/** Story 11.10: co-players of a slot of an event, and what they get. */
final class EventSlotCoPlayersTest extends FunctionalTestCase
{
    private string $eventId;
    private User $alice;
    private User $bob;
    private User $carol;
    private Registration $aliceRegistration;
    private Registration $bobRegistration;
    private string $slotId;

    protected function setUp(): void
    {
        parent::setUp();
        $now = new \DateTimeImmutable();
        $this->eventId = $this->createEvent('ArchiLAN 4', $now->modify('-1 day'), $now->modify('+1 day'))->getId();
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $this->alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $this->bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $this->carol = $this->createUser('carol@example.org', ['ROLE_USER'], 'Carol');

        $this->aliceRegistration = $this->confirmed($this->alice, [$game->getId()]);
        $this->bobRegistration = $this->confirmed($this->bob, [$game->getId()]);
        // Carol registered but never confirmed.
        $this->createRegistration($this->eventId, $this->carol->getId());

        $slot = $this->aliceRegistration->getGameSlots()[0] ?? null;
        self::assertIsArray($slot);
        $this->slotId = $slot['slotId'];
    }

    public function testTheHolderAddsAConfirmedRegistrant(): void
    {
        $this->loginAs($this->alice);
        $this->client->jsonRequest('PUT', $this->url(), ['userIds' => [$this->bob->getId()]]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([['userId' => $this->bob->getId(), 'displayName' => 'Bob']], $this->data()['coPlayers']);

        // Sending the same list again changes nothing.
        $this->client->jsonRequest('PUT', $this->url(), ['userIds' => [$this->bob->getId()]]);
        self::assertResponseStatusCodeSame(200);

        $this->client->request('GET', $this->url());
        self::assertResponseStatusCodeSame(200);
        $data = $this->data();
        self::assertTrue($data['canManage']);
        self::assertSame([['userId' => $this->bob->getId(), 'displayName' => 'Bob']], $data['candidates'], 'confirmed registrants only, the holder left out');
    }

    public function testOnlyConfirmedRegistrantsMayBeAdded(): void
    {
        $this->loginAs($this->alice);
        $this->client->jsonRequest('PUT', $this->url(), ['userIds' => [$this->carol->getId()]]);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('PUT', $this->url(), ['userIds' => [$this->alice->getId()]]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnotherRegistrantMayNotManageButAnAdminMay(): void
    {
        $this->loginAs($this->bob);
        $this->client->jsonRequest('PUT', $this->url(), ['userIds' => [$this->bob->getId()]]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->url());
        self::assertResponseStatusCodeSame(403);

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);
        $this->client->jsonRequest('PUT', $this->url(), ['userIds' => [$this->bob->getId()]]);
        self::assertResponseStatusCodeSame(200);

        // Now a co-player, Bob reads the roster without managing it.
        $this->loginAs($this->bob);
        $this->client->request('GET', $this->url());
        self::assertResponseStatusCodeSame(200);
        $data = $this->data();
        self::assertFalse($data['canManage']);
        self::assertSame([], $data['candidates']);

        $this->client->request('GET', sprintf('/api/v1/events/%s/slots/unknown/co-players', $this->eventId));
        self::assertResponseStatusCodeSame(404);
    }

    public function testACoPlayerLeftOutOfTheSessionReachesItThroughTheSlot(): void
    {
        $game = $this->aliceRegistration->getGameSlots()[0]['gameId'] ?? null;
        self::assertIsString($game);
        $session = Session::createRunning(bin2hex(random_bytes(16)), $this->eventId, 'localhost', 38281, 'pass', null, new \DateTimeImmutable());
        $this->entityManager->persist($session);
        // Story 11.9: only Alice's slot went into the session.
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $this->aliceRegistration->getId(), $game, 'Alice_HK', 1, $this->slotId));
        $this->entityManager->flush();

        $this->loginAs($this->bob);
        $this->client->request('GET', $this->connectionUrl());
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->data()['session']);

        $this->loginAs($this->alice);
        $this->client->jsonRequest('PUT', $this->url(), ['userIds' => [$this->bob->getId()]]);
        self::assertResponseStatusCodeSame(200);

        $this->loginAs($this->bob);
        $this->client->request('GET', $this->connectionUrl());
        self::assertResponseStatusCodeSame(200);
        $data = $this->data();
        self::assertIsArray($data['session']);
        self::assertSame($session->getId(), $data['session']['id']);
        self::assertIsArray($data['slots']);
        self::assertCount(1, $data['slots']);
        self::assertIsArray($data['slots'][0]);
        self::assertSame(['Alice_HK', $this->slotId, true], [$data['slots'][0]['slotName'], $data['slots'][0]['slotId'], $data['slots'][0]['coPlayed']]);

        // Removed, the access goes.
        $this->loginAs($this->alice);
        $this->client->jsonRequest('PUT', $this->url(), ['userIds' => []]);
        self::assertResponseStatusCodeSame(200);
        $this->loginAs($this->bob);
        $this->client->request('GET', $this->connectionUrl());
        self::assertNull($this->data()['session']);
    }

    /**
     * @param list<string> $gameIds
     */
    private function confirmed(User $user, array $gameIds): Registration
    {
        $registration = $this->createRegistration($this->eventId, $user->getId(), Registration::STATUS_RESERVED, $gameIds);
        $registration->confirm(new \DateTimeImmutable('2026-05-02T10:00:00+00:00'));
        $this->entityManager->flush();

        return $registration;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    private function url(): string
    {
        return sprintf('/api/v1/events/%s/slots/%s/co-players', $this->eventId, $this->slotId);
    }

    private function connectionUrl(): string
    {
        return sprintf('/api/v1/registrations/%s/session-connection', $this->bobRegistration->getId());
    }
}
