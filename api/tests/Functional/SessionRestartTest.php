<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;
use App\PersonalRuns\Domain\Run;
use App\Sessions\Application\Message\ResumeRunJob;
use App\Sessions\Domain\Session;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SessionRestartTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');
        $transport->reset();
    }

    // ─── POST /restart endpoint ───────────────────────────────────────────────

    public function testAdminCanRestartIdleSession(): void
    {
        $admin = $this->createAdmin('admin@example.org');
        $session = $this->createIdleSessionWithSave('sessions/abc/saves/save.apsave');

        $this->loginAs($admin);
        $this->client->jsonRequest('POST', '/api/v1/sessions/'.$session->getId().'/restart');

        self::assertResponseStatusCodeSame(202);
        $data = $this->responseData();
        self::assertSame($session->getId(), $data['sessionId']);
        self::assertSame(Session::STATUS_RESTARTING, $data['status']);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $reloaded);
        self::assertSame(Session::STATUS_RESTARTING, $reloaded->getStatus());

        $resumeJobs = $this->getResumeJobs($session->getId());
        self::assertCount(1, $resumeJobs);
        self::assertSame('sessions/abc/saves/save.apsave', $resumeJobs[0]->lastSaveKey);
    }

    public function testOwnerCanRestartViaPersonalRun(): void
    {
        $owner = $this->createUser('owner@example.org');
        $session = $this->createIdleSessionWithSave('sessions/abc/saves/save.apsave');
        $this->linkPersonalRunToSession($owner->getId(), $session->getId());

        $this->loginAs($owner);
        $this->client->jsonRequest('POST', '/api/v1/sessions/'.$session->getId().'/restart');

        self::assertResponseStatusCodeSame(202);
        $resumeJobs = $this->getResumeJobs($session->getId());
        self::assertCount(1, $resumeJobs);
    }

    public function testNonOwnerNonAdminReturns403(): void
    {
        $other = $this->createUser('other@example.org');
        $session = $this->createIdleSessionWithSave('sessions/abc/saves/save.apsave');
        // No Run linking other to session

        $this->loginAs($other);
        $this->client->jsonRequest('POST', '/api/v1/sessions/'.$session->getId().'/restart');

        self::assertResponseStatusCodeSame(403);
    }

    public function testPausedWithoutSaveIsStillRelaunchable(): void
    {
        // Without a save the run restarts from the seed on the retained volume (story 17.10) —
        // no recreation needed, so the restart is accepted.
        $admin = $this->createAdmin('admin@example.org');
        $session = $this->createIdleSessionWithoutSave();

        $this->loginAs($admin);
        $this->client->jsonRequest('POST', '/api/v1/sessions/'.$session->getId().'/restart');

        self::assertResponseStatusCodeSame(202);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $reloaded);
        self::assertSame(Session::STATUS_RESTARTING, $reloaded->getStatus());

        $resumeJobs = $this->getResumeJobs($session->getId());
        self::assertCount(1, $resumeJobs);
    }

    public function testNullSaveKeyIsStillRelaunchable(): void
    {
        $admin = $this->createAdmin('admin@example.org');
        $session = $this->createIdleSessionNullSaveKey();

        $this->loginAs($admin);
        $this->client->jsonRequest('POST', '/api/v1/sessions/'.$session->getId().'/restart');

        self::assertResponseStatusCodeSame(202);
        $resumeJobs = $this->getResumeJobs($session->getId());
        self::assertCount(1, $resumeJobs);
    }

    public function testStoppedSessionIsRelaunchable(): void
    {
        // A paused run stays "idle" even when its session drifted to "stopped" (orchestrateur
        // session.stopped); it must still be relaunchable (story 17.10 follow-up).
        $admin = $this->createAdmin('admin@example.org');
        $session = $this->createStoppedSession();

        $this->loginAs($admin);
        $this->client->jsonRequest('POST', '/api/v1/sessions/'.$session->getId().'/restart');

        self::assertResponseStatusCodeSame(202);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $reloaded);
        self::assertSame(Session::STATUS_RESTARTING, $reloaded->getStatus());
    }

    public function testNonIdleSessionReturns422InvalidStatus(): void
    {
        $admin = $this->createAdmin('admin@example.org');
        $session = $this->createRunningSession();

        $this->loginAs($admin);
        $this->client->jsonRequest('POST', '/api/v1/sessions/'.$session->getId().'/restart');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_session_status', $this->responseErrorCode());
    }

    // ─── POST /restarted callback ─────────────────────────────────────────────

    public function testRestartedCallbackTransitionsToRunning(): void
    {
        $session = $this->createRestartingSession();

        $beforeRestarted = new \DateTimeImmutable();

        $this->client->request(
            'POST',
            '/api/v1/sessions/'.$session->getId().'/restarted',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            json_encode([
                'connectionHost' => '10.0.0.1',
                'connectionPort' => 9042,
                'bridgePort' => 5000,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);

        $afterRestarted = new \DateTimeImmutable();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $reloaded);
        self::assertSame(Session::STATUS_RUNNING, $reloaded->getStatus());
        self::assertSame('10.0.0.1', $reloaded->getHost());
        self::assertSame(9042, $reloaded->getPort());

        $lastActivity = $reloaded->getLastActivityAt();
        self::assertNotNull($lastActivity);
        self::assertGreaterThanOrEqual($beforeRestarted->getTimestamp(), $lastActivity->getTimestamp());
        self::assertLessThanOrEqual($afterRestarted->getTimestamp() + 1, $lastActivity->getTimestamp());
    }

    public function testRestartedCallbackAlreadyRunningReturns200Idempotent(): void
    {
        $session = $this->createRunningSession();

        $this->client->request(
            'POST',
            '/api/v1/sessions/'.$session->getId().'/restarted',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['connectionHost' => '10.0.0.1', 'connectionPort' => 9042, 'bridgePort' => 5000], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testRestartedCallbackUnexpectedStatusReturns422(): void
    {
        $session = $this->createIdleSessionWithSave('sessions/abc/saves/save.apsave');

        $this->client->request(
            'POST',
            '/api/v1/sessions/'.$session->getId().'/restarted',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['connectionHost' => '10.0.0.1', 'connectionPort' => 9042, 'bridgePort' => 5000], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSame('unexpected_status', $this->responseErrorCode());
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createAdmin(string $email): User
    {
        return $this->createUser($email, ['ROLE_USER', 'ROLE_ADMIN']);
    }

    private function createRunningSession(): Session
    {
        $session = Session::create(bin2hex(random_bytes(16)), 'evt-001', new \DateTimeImmutable());
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $now = new \DateTimeImmutable();
        foreach ([
            Session::STATUS_VALIDATING,
            Session::STATUS_READY,
            Session::STATUS_GENERATING,
            Session::STATUS_GENERATED,
            Session::STATUS_LAUNCHING,
        ] as $status) {
            $session->transition($status, $now);
        }
        $session->transition(Session::STATUS_RUNNING, $now, '10.0.0.1', 9042, 'secret');
        $this->entityManager->flush();

        return $session;
    }

    private function createIdleSessionWithSave(string $saveKey): Session
    {
        $session = $this->createRunningSession();
        $session->markIdle($saveKey, false, new \DateTimeImmutable());
        $this->entityManager->flush();

        return $session;
    }

    private function createIdleSessionWithoutSave(): Session
    {
        $session = $this->createRunningSession();
        $session->markIdle(null, true, new \DateTimeImmutable());
        $this->entityManager->flush();

        return $session;
    }

    private function createIdleSessionNullSaveKey(): Session
    {
        $session = $this->createRunningSession();
        $session->markIdle(null, false, new \DateTimeImmutable());
        $this->entityManager->flush();

        return $session;
    }

    private function createStoppedSession(): Session
    {
        $session = $this->createRunningSession();
        $session->transition(Session::STATUS_STOPPED, new \DateTimeImmutable());
        $this->entityManager->flush();

        return $session;
    }

    private function createRestartingSession(): Session
    {
        $session = $this->createIdleSessionWithSave('sessions/abc/saves/save.apsave');
        $session->markRestarting(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $session;
    }

    private function linkPersonalRunToSession(string $ownerId, string $sessionId): Run
    {
        $run = Run::create($ownerId, 'Test Run', new \DateTimeImmutable());
        $run->markStopped(new \DateTimeImmutable()); // set to idle
        $reflection = new \ReflectionProperty(Run::class, 'sessionId');
        $reflection->setValue($run, $sessionId);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    /** @return list<ResumeRunJob> */
    private function getResumeJobs(string $sessionId): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');

        return array_values(array_filter(
            array_map(static fn ($e) => $e->getMessage(), $transport->getSent()),
            static fn ($msg) => $msg instanceof ResumeRunJob && $msg->sessionId === $sessionId,
        ));
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        $decoded = $this->decodedResponse();
        $data = $decoded['data'] ?? null;
        self::assertIsArray($data);

        return $this->stringKeyArray($data);
    }

    private function responseErrorCode(): string
    {
        $decoded = $this->decodedResponse();
        $error = $decoded['error'] ?? null;
        self::assertIsArray($error);
        $code = $error['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    /** @return array<string, mixed> */
    private function decodedResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $this->stringKeyArray($decoded);
    }

    /**
     * @param array<mixed> $input
     *
     * @return array<string, mixed>
     */
    private function stringKeyArray(array $input): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }
}
