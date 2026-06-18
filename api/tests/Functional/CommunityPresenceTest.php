<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;

final class CommunityPresenceTest extends FunctionalTestCase
{
    public function testProfileReportsPlayingPresenceForARunningSession(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $event = $this->createEvent('LAN', $now, $now->modify('+1 day'), 20);
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $reg = $this->createRegistration($event->getId(), $alice->getId());
        $session = $this->makeRunningSession($event->getId(), $now);

        $slot = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $reg->getId(), $game->getId(), 'Alice', 0);
        $this->entityManager->persist($slot);
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice');
        self::assertResponseIsSuccessful();

        $presence = $this->data()['presence'] ?? null;
        self::assertIsArray($presence);
        self::assertTrue($presence['playing']);
        self::assertSame($session->getId(), $presence['sessionId']);
        self::assertSame('Hollow Knight', $presence['game']);
    }

    public function testProfileNotPlayingWithoutARunningSession(): void
    {
        $this->createUser('bob@example.org', slug: 'bob');

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/bob');
        self::assertResponseIsSuccessful();

        $presence = $this->data()['presence'] ?? null;
        self::assertIsArray($presence);
        self::assertFalse($presence['playing']);
        self::assertNull($presence['sessionId']);
        self::assertNull($presence['game']);
    }

    private function makeRunningSession(string $eventId, \DateTimeImmutable $now): Session
    {
        $session = Session::create(bin2hex(random_bytes(16)), $eventId, $now);
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

    /**
     * @return array<mixed>
     */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
