<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Sessions\Application\Message\GenerateRunJob;
use App\Sessions\Application\Message\RestartRunJob;
use App\Sessions\Application\Message\StartRunJob;
use App\Sessions\Application\Message\StopRunJob;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

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

    public function testGenerateTransitionsToGeneratingAndDispatchesMessage(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistSession('evt-001', Session::STATUS_READY);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/generate', $session->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('generating', $data['status']);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_generation');
        self::assertCount(1, $transport->getSent());
        /** @var GenerateRunJob $message */
        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(GenerateRunJob::class, $message);
        self::assertSame($session->getId(), $message->sessionId);
        self::assertSame('generate', $message->phase);
    }

    // ─── Generate callback ────────────────────────────────────────────────────

    public function testCallbackGeneratedTransitionsToGenerated(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistSession('evt-001', Session::STATUS_GENERATING);

        $this->sendCallback($session->getId(), ['status' => 'generated']);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('generated', $data['status']);
    }

    public function testCallbackFailedFromGeneratingTransitionsToFailed(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistSession('evt-001', Session::STATUS_GENERATING);

        $this->sendCallback($session->getId(), [
            'status' => 'failed',
            'errors' => ['World 1 is missing required options.'],
        ]);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('failed', $data['status']);
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

    public function testLaunchTransitionsToLaunchingAndDispatchesMessage(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistSession('evt-001', Session::STATUS_GENERATED);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/launch', $session->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('launching', $data['status']);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');
        self::assertCount(1, $transport->getSent());
        /** @var StartRunJob $message */
        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(StartRunJob::class, $message);
        self::assertSame($session->getId(), $message->sessionId);
    }

    // ─── Launch callback ──────────────────────────────────────────────────────

    public function testCallbackRunningTransitionsToRunning(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistSession('evt-001', Session::STATUS_LAUNCHING);

        $this->sendCallback($session->getId(), [
            'status' => 'running',
            'host' => 'runner-local',
            'port' => 38281,
            'password' => 'secretpass',
        ]);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('running', $data['status']);
        self::assertSame('runner-local', $data['host']);
        self::assertSame(38281, $data['port']);
        self::assertSame('secretpass', $data['password']);
    }

    public function testCallbackFailedFromLaunchingTransitionsToFailed(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistSession('evt-001', Session::STATUS_LAUNCHING);

        $this->sendCallback($session->getId(), ['status' => 'failed']);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('failed', $data['status']);
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

    public function testStopTransitionsToStoppedAndDispatchesMessage(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistRunningSession('evt-001');

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/stop', $session->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('stopped', $data['status']);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');
        self::assertCount(1, $transport->getSent());
        /** @var StopRunJob $message */
        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(StopRunJob::class, $message);
        self::assertSame($session->getId(), $message->sessionId);
        self::assertSame(38281, $message->port);
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

    public function testRestartTransitionsToLaunchingAndDispatchesMessage(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistCrashedSession('evt-001');

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/restart', $session->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('launching', $data['status']);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');
        self::assertCount(1, $transport->getSent());
        /** @var RestartRunJob $message */
        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(RestartRunJob::class, $message);
        self::assertSame($session->getId(), $message->sessionId);
        self::assertSame(38281, $message->port);
        self::assertSame('secretpass', $message->password);
    }

    // ─── Restart callback ─────────────────────────────────────────────────────

    public function testCallbackRunningFromLaunchingAfterRestartTransitionsToRunning(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistSession('evt-001', Session::STATUS_LAUNCHING);

        $this->sendCallback($session->getId(), [
            'status' => 'running',
            'host' => 'runner-local',
            'port' => 38281,
            'password' => 'secretpass',
        ]);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('running', $data['status']);
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

        // Walk the state machine to reach the target status.
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
        return $this->persistSession($eventId, Session::STATUS_RUNNING);
    }

    private function persistCrashedSession(string $eventId): Session
    {
        $running = $this->persistRunningSession($eventId);
        $this->patchStatus($running->getId(), Session::STATUS_CRASHED);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Session::class, $running->getId());
        self::assertInstanceOf(Session::class, $refreshed);

        return $refreshed;
    }

    /**
     * Returns the ordered list of statuses to PATCH to reach $target from draft.
     *
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

    /** @param array<string, mixed> $payload */
    private function sendCallback(string $sessionId, array $payload): void
    {
        $this->client->request(
            'POST',
            sprintf('/api/v1/internal/sessions/%s/runner-callback', $sessionId),
            [],
            [],
            ['HTTP_X_INTERNAL_SECRET' => 'test-runner-secret', 'CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
