<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionPlayersSnapshot;

final class PlayersSnapshotTest extends FunctionalTestCase
{
    private const string SECRET = 'test-runner-secret'; // matches CENTRAL_API_SECRET in .env.test

    public function testPushPersistsAndOverwritesTheSnapshot(): void
    {
        $sessionId = 'run-snap-1';
        $uri = sprintf('/api/v1/internal/sessions/%s/players-push', $sessionId);

        $first = ['slots' => ['1' => ['slot_name' => 'Alice_HK1', 'checks_done' => 5, 'checks_total' => 47, 'items_received' => 3, 'client_status' => 20, 'goal_reached_at' => null]]];
        $this->client->jsonRequest('POST', $uri, $first, ['HTTP_X_INTERNAL_SECRET' => self::SECRET]);
        self::assertResponseStatusCodeSame(200);

        $second = ['slots' => ['1' => ['slot_name' => 'Alice_HK1', 'checks_done' => 9, 'checks_total' => 47, 'items_received' => 6, 'client_status' => 20, 'goal_reached_at' => null]]];
        $this->client->jsonRequest('POST', $uri, $second, ['HTTP_X_INTERNAL_SECRET' => self::SECRET]);
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(SessionPlayersSnapshot::class)->findBy(['sessionId' => $sessionId]);
        self::assertCount(1, $rows, 'one row per session, overwritten on each push');
        self::assertSame($second, $rows[0]->getPayload());
    }

    public function testIdleSessionServesTheStaleSnapshot(): void
    {
        $session = $this->createIdleSession('run-snap-idle', 'evt-001');
        $player = $this->createConfirmedRegistrant('snap-idle@example.org', 'evt-001');

        $payload = ['slots' => ['1' => ['slot_name' => 'Alice_HK1', 'checks_done' => 12, 'checks_total' => 47, 'items_received' => 8, 'client_status' => 20, 'goal_reached_at' => null]]];
        $this->client->jsonRequest(
            'POST',
            sprintf('/api/v1/internal/sessions/%s/players-push', $session->getId()),
            $payload,
            ['HTTP_X_INTERNAL_SECRET' => self::SECRET],
        );
        self::assertResponseStatusCodeSame(200);

        $this->loginAs($player);
        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/players', $session->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        self::assertSame($payload, $response['data']);
        $meta = $response['meta'];
        self::assertIsArray($meta);
        self::assertTrue($meta['stale']);
        self::assertIsString($meta['updatedAt']);
    }

    public function testIdleSessionWithoutSnapshotKeeps409(): void
    {
        $session = $this->createIdleSession('run-snap-none', 'evt-001');
        $player = $this->createConfirmedRegistrant('snap-none@example.org', 'evt-001');
        $this->loginAs($player);

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/players', $session->getId()));
        self::assertResponseStatusCodeSame(409);
    }

    private function createConfirmedRegistrant(string $email, string $eventId): User
    {
        $user = $this->createUser($email, ['ROLE_USER'], 'Alice');
        $registration = $this->createRegistration($eventId, $user->getId());
        $registration->confirm(new \DateTimeImmutable('2026-07-30T10:00:00+00:00'));
        $this->entityManager->flush();

        return $user;
    }

    private function createIdleSession(string $id, string $eventId): Session
    {
        $now = new \DateTimeImmutable();
        $session = Session::create($id, $eventId, $now);
        $session->transition(Session::STATUS_VALIDATING, $now);
        $session->transition(Session::STATUS_READY, $now);
        $session->transition(Session::STATUS_GENERATING, $now);
        $session->transition(Session::STATUS_GENERATED, $now);
        $session->transition(Session::STATUS_LAUNCHING, $now);
        $session->transition(Session::STATUS_RUNNING, $now, 'bridge.local', 38281, 'secret', 5000);
        $session->markIdle('saves/last.apsave', false, $now);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }
}
