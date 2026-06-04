<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use Doctrine\ORM\Tools\SchemaTool;

final class RunnerGeneratePipelineTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Session::class),
            $this->entityManager->getClassMetadata(SessionSlot::class),
            $this->entityManager->getClassMetadata(Registration::class),
            $this->entityManager->getClassMetadata(Game::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    // ─── Generate endpoint ────────────────────────────────────────────────────

    public function testGenerateEndpointRequiresAdmin(): void
    {
        $player = $this->createUser('player@example.org', ['ROLE_USER'], 'Player');
        $this->loginAs($player);
        $session = $this->persistSession('evt-001', Session::STATUS_READY);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/generate', $session->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testGenerateEndpointReturns404ForUnknownSession(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/sessions/no-such-session/generate');

        self::assertResponseStatusCodeSame(404);
    }

    public function testGenerateReturns409WhenSessionNotReady(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistSession('evt-001', Session::STATUS_DRAFT);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/generate', $session->getId()));

        self::assertResponseStatusCodeSame(409);
    }

    public function testGenerateTransitionsToGenerating(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistSession('evt-001', Session::STATUS_READY);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/generate', $session->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('generating', $data['status']);
    }

    // ─── Launch endpoint ──────────────────────────────────────────────────────

    public function testLaunchEndpointRequiresAdmin(): void
    {
        $player = $this->createUser('player@example.org', ['ROLE_USER'], 'Player');
        $this->loginAs($player);
        $session = $this->persistSession('evt-001', Session::STATUS_GENERATED);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/launch', $session->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testLaunchReturns409WhenSessionNotGenerated(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistSession('evt-001', Session::STATUS_READY);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/launch', $session->getId()));

        self::assertResponseStatusCodeSame(409);
    }

    public function testLaunchTransitionsToLaunching(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistSession('evt-001', Session::STATUS_GENERATED);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/launch', $session->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('launching', $data['status']);
    }

    // ─── Stop endpoint ────────────────────────────────────────────────────────

    public function testStopEndpointRequiresAdmin(): void
    {
        $player = $this->createUser('player@example.org', ['ROLE_USER'], 'Player');
        $this->loginAs($player);
        $session = $this->persistRunningSession('evt-001');

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/stop', $session->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testStopTransitionsToStopped(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistRunningSession('evt-001');

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/stop', $session->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('stopped', $data['status']);
    }

    // ─── Restart endpoint ─────────────────────────────────────────────────────

    public function testRestartEndpointRequiresAdmin(): void
    {
        $player = $this->createUser('player@example.org', ['ROLE_USER'], 'Player');
        $this->loginAs($player);
        $session = $this->persistCrashedSession('evt-001');

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/restart', $session->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testRestartReturns409WhenSessionNotCrashed(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistSession('evt-001', Session::STATUS_STOPPED);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/restart', $session->getId()));

        self::assertResponseStatusCodeSame(409);
    }

    public function testRestartTransitionsToLaunching(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistCrashedSession('evt-001');

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/restart', $session->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('launching', $data['status']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createAdmin(): User
    {
        return $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
    }

    private function persistSession(string $eventId, string $status): Session
    {
        $session = Session::create(bin2hex(random_bytes(8)), $eventId, new \DateTimeImmutable());
        $this->entityManager->persist($session);
        $this->entityManager->flush();
        $id = $session->getId();

        $path = $this->transitionPath($status);
        foreach ($path as $step) {
            $this->patchStatus($id, $step);
        }

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Session::class, $id);
        self::assertInstanceOf(Session::class, $refreshed);

        return $refreshed;
    }

    private function persistRunningSession(string $eventId): Session
    {
        $now = new \DateTimeImmutable();
        $session = Session::create(bin2hex(random_bytes(8)), $eventId, $now);
        $session->transition(Session::STATUS_VALIDATING, $now);
        $session->transition(Session::STATUS_READY, $now);
        $session->transition(Session::STATUS_GENERATING, $now);
        $session->transition(Session::STATUS_GENERATED, $now);
        $session->transition(Session::STATUS_LAUNCHING, $now);
        $session->transition(Session::STATUS_RUNNING, $now, '10.0.0.1', 38281, 'secretpass');
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    private function persistCrashedSession(string $eventId): Session
    {
        $now = new \DateTimeImmutable();
        $session = Session::create(bin2hex(random_bytes(8)), $eventId, $now);
        $session->transition(Session::STATUS_VALIDATING, $now);
        $session->transition(Session::STATUS_READY, $now);
        $session->transition(Session::STATUS_GENERATING, $now);
        $session->transition(Session::STATUS_GENERATED, $now);
        $session->transition(Session::STATUS_LAUNCHING, $now);
        $session->transition(Session::STATUS_RUNNING, $now, '10.0.0.1', 38281, 'secretpass');
        $session->transition(Session::STATUS_CRASHED, $now);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    /**
     * @return list<string>
     */
    private function transitionPath(string $target): array
    {
        $paths = [
            Session::STATUS_DRAFT => [],
            Session::STATUS_VALIDATING => [Session::STATUS_VALIDATING],
            Session::STATUS_READY => [Session::STATUS_VALIDATING, Session::STATUS_READY],
            Session::STATUS_GENERATING => [Session::STATUS_VALIDATING, Session::STATUS_READY, Session::STATUS_GENERATING],
            Session::STATUS_GENERATED => [Session::STATUS_VALIDATING, Session::STATUS_READY, Session::STATUS_GENERATING, Session::STATUS_GENERATED],
            Session::STATUS_LAUNCHING => [Session::STATUS_VALIDATING, Session::STATUS_READY, Session::STATUS_GENERATING, Session::STATUS_GENERATED, Session::STATUS_LAUNCHING],
            Session::STATUS_RUNNING => [Session::STATUS_VALIDATING, Session::STATUS_READY, Session::STATUS_GENERATING, Session::STATUS_GENERATED, Session::STATUS_LAUNCHING, Session::STATUS_RUNNING],
            Session::STATUS_STOPPED => [Session::STATUS_VALIDATING, Session::STATUS_READY, Session::STATUS_GENERATING, Session::STATUS_GENERATED, Session::STATUS_LAUNCHING, Session::STATUS_RUNNING, Session::STATUS_STOPPED],
        ];

        return $paths[$target] ?? [];
    }

    private function patchStatus(
        string $sessionId,
        string $status,
        string $host = '10.0.0.1',
        int $port = 38281,
        string $password = 'secretpass',
    ): void {
        $body = ['status' => $status];
        if (Session::STATUS_RUNNING === $status) {
            $body['host'] = $host;
            $body['port'] = $port;
            $body['password'] = $password;
        }
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/sessions/%s/status', $sessionId), $body);
    }
}
