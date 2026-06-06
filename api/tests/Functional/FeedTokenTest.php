<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;

final class FeedTokenTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testFeedTokenRequiresAuth(): void
    {
        $session = $this->createSession('run-auth-1', 'evt-001');

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/feed-token', $session->getId()));
        self::assertResponseStatusCodeSame(401);
    }

    public function testFeedTokenForbidsNonRegistrant(): void
    {
        $session = $this->createSession('run-noreg-1', 'evt-001');
        $player = $this->createPlayer('alice@example.org', 'Alice');
        $this->loginAs($player);

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/feed-token', $session->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testFeedTokenForbidsNonConfirmedRegistrant(): void
    {
        $session = $this->createSession('run-pending-1', 'evt-001');
        $player = $this->createPlayer('bob@example.org', 'Bob');
        $this->makeRegistration($player->getId(), 'evt-001', confirmed: false);
        $this->loginAs($player);

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/feed-token', $session->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testFeedTokenAllowsConfirmedRegistrant(): void
    {
        $session = $this->createSession('run-ok-1', 'evt-001');
        $player = $this->createPlayer('charlie@example.org', 'Charlie');
        $this->makeRegistration($player->getId(), 'evt-001', confirmed: true);
        $this->loginAs($player);

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/feed-token', $session->getId()));
        self::assertResponseStatusCodeSame(200);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertIsString($data['token']);
        self::assertIsString($data['hubUrl']);
        self::assertSame('runs/run-ok-1/feed', $data['topic']);
    }

    public function testFeedTokenAllowsAdmin(): void
    {
        $session = $this->createSession('run-admin-1', 'evt-001');
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/sessions/%s/feed-token', $session->getId()));
        self::assertResponseStatusCodeSame(200);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('runs/run-admin-1/feed', $data['topic']);
    }

    public function testFeedTokenReturns404ForUnknownSession(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/sessions/nonexistent-run/feed-token');
        self::assertResponseStatusCodeSame(404);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function createSession(string $id, string $eventId): Session
    {
        $session = Session::create($id, $eventId, new \DateTimeImmutable());
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    private function createAdmin(): User
    {
        return $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
    }

    private function createPlayer(string $email, string $displayName): User
    {
        return $this->createUser($email, ['ROLE_USER'], $displayName);
    }

    private function makeRegistration(string $userId, string $eventId, bool $confirmed): Registration
    {
        $now = new \DateTimeImmutable('2026-05-06T10:00:00+00:00');
        $registration = $this->createRegistration($eventId, $userId);
        if ($confirmed) {
            $registration->confirm($now);
            $this->entityManager->flush();
        }

        return $registration;
    }
}
