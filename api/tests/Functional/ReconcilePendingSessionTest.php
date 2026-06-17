<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\PersonalRuns\Domain\Run;
use App\Sessions\Application\SessionLifecycleManager;
use App\Sessions\Domain\Session;
use App\Sessions\Infrastructure\NullRunnerGateway;

/**
 * Story 17.14: the guard-rail that force-resolves a session stuck in a transitional ("pending")
 * status. Exercises SessionLifecycleManager::reconcilePending - the shared decision used by both the
 * scheduled watchdog and the manual force button - and asserts the linked personal run is advanced.
 */
final class ReconcilePendingSessionTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        NullRunnerGateway::reset();
    }

    protected function tearDown(): void
    {
        NullRunnerGateway::reset();
        parent::tearDown();
    }

    public function testStuckRestartingForcedToRunningWhenOrchestrateurIsRunning(): void
    {
        $session = $this->createRestartingSession();
        $run = $this->linkRun($session->getId(), Run::STATUS_RESTARTING);

        NullRunnerGateway::$nextSessionInfo = ['status' => 'running', 'bridgePort' => 5000, 'apPort' => 48281];

        $result = $this->reconciler()->reconcilePending($session->getId());

        self::assertSame('forced_running', $result['action'] ?? null);
        $this->assertSessionStatus($session->getId(), Session::STATUS_RUNNING);
        $this->assertRunStatus($run->getId(), Run::STATUS_ACTIVE);
    }

    public function testStuckRestartingForcedToIdleWhenOrchestrateurIsDown(): void
    {
        $session = $this->createRestartingSession();
        $run = $this->linkRun($session->getId(), Run::STATUS_RESTARTING);

        // NullRunnerGateway::$nextSessionInfo stays null → the orchestrateur has nothing running.
        $result = $this->reconciler()->reconcilePending($session->getId());

        self::assertSame('forced_idle', $result['action'] ?? null);
        $this->assertSessionStatus($session->getId(), Session::STATUS_IDLE);
        $this->assertRunStatus($run->getId(), Run::STATUS_IDLE);
    }

    public function testStuckValidatingForcedToDraftAndRunReset(): void
    {
        $session = $this->createSessionAt(Session::STATUS_VALIDATING);
        $run = $this->linkRun($session->getId(), Run::STATUS_STARTING);

        $result = $this->reconciler()->reconcilePending($session->getId());

        self::assertSame('forced_draft', $result['action'] ?? null);
        $this->assertSessionStatus($session->getId(), Session::STATUS_DRAFT);
        $this->assertRunStatus($run->getId(), Run::STATUS_DRAFT);
    }

    public function testStuckGeneratingForcedToFailedAndRunReset(): void
    {
        $session = $this->createSessionAt(Session::STATUS_GENERATING);
        $run = $this->linkRun($session->getId(), Run::STATUS_STARTING);

        // Orchestrateur reports nothing → the generation is considered dead.
        $result = $this->reconciler()->reconcilePending($session->getId());

        self::assertSame('forced_failed', $result['action'] ?? null);
        $this->assertSessionStatus($session->getId(), Session::STATUS_FAILED);
        // The run is reset off "starting" so the owner can fix & retry.
        $this->assertRunStatus($run->getId(), Run::STATUS_DRAFT);
    }

    public function testStuckRunningCrashRecoveredToIdle(): void
    {
        $session = $this->createRunningSession();
        $run = $this->linkRun($session->getId(), Run::STATUS_ACTIVE);

        $result = $this->reconciler()->reconcilePending($session->getId());

        self::assertSame('crash_recovered', $result['action'] ?? null);
        $this->assertSessionStatus($session->getId(), Session::STATUS_IDLE);
        $this->assertRunStatus($run->getId(), Run::STATUS_IDLE);
    }

    // ─── POST /reconcile endpoint ─────────────────────────────────────────────

    public function testOwnerCanForceResolveStuckRestartingViaEndpoint(): void
    {
        $owner = $this->createUser('owner@example.org');
        $session = $this->createRestartingSession();
        $this->linkRun($session->getId(), Run::STATUS_RESTARTING, $owner->getId());

        $this->loginAs($owner);
        $this->client->jsonRequest('POST', '/api/v1/sessions/'.$session->getId().'/reconcile');

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('forced_idle', $data['action']);
        $this->assertSessionStatus($session->getId(), Session::STATUS_IDLE);
    }

    public function testNonOwnerCannotForceResolve(): void
    {
        $owner = $this->createUser('owner@example.org');
        $other = $this->createUser('other@example.org');
        $session = $this->createRestartingSession();
        $this->linkRun($session->getId(), Run::STATUS_RESTARTING, $owner->getId());

        $this->loginAs($other);
        $this->client->jsonRequest('POST', '/api/v1/sessions/'.$session->getId().'/reconcile');

        self::assertResponseStatusCodeSame(403);
    }

    public function testForceResolveRejectsNonPendingSession(): void
    {
        $owner = $this->createUser('owner@example.org');
        $session = $this->createRunningSession(); // running is not a forceable "pending" state
        $this->linkRun($session->getId(), Run::STATUS_ACTIVE, $owner->getId());

        $this->loginAs($owner);
        $this->client->jsonRequest('POST', '/api/v1/sessions/'.$session->getId().'/reconcile');

        self::assertResponseStatusCodeSame(422);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function reconciler(): SessionLifecycleManager
    {
        $service = self::getContainer()->get(SessionLifecycleManager::class);
        self::assertInstanceOf(SessionLifecycleManager::class, $service);

        return $service;
    }

    private function createSessionAt(string $targetStatus): Session
    {
        $now = new \DateTimeImmutable();
        $session = Session::create(bin2hex(random_bytes(16)), 'evt-001', $now);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $path = [
            Session::STATUS_VALIDATING,
            Session::STATUS_READY,
            Session::STATUS_GENERATING,
            Session::STATUS_GENERATED,
            Session::STATUS_LAUNCHING,
        ];

        foreach ($path as $status) {
            $session->transition($status, $now);
            if ($status === $targetStatus) {
                break;
            }
        }
        $this->entityManager->flush();

        return $session;
    }

    private function createRunningSession(): Session
    {
        $now = new \DateTimeImmutable();
        $session = $this->createSessionAt(Session::STATUS_LAUNCHING);
        $session->transition(Session::STATUS_RUNNING, $now, '10.0.0.1', 9042, 'secret');
        $this->entityManager->flush();

        return $session;
    }

    private function createRestartingSession(): Session
    {
        $session = $this->createRunningSession();
        $session->markIdle('sessions/abc/saves/save.apsave', false, new \DateTimeImmutable());
        $session->markRestarting(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $session;
    }

    private function linkRun(string $sessionId, string $status, string $ownerId = 'owner-1'): Run
    {
        $now = new \DateTimeImmutable();
        $run = Run::create($ownerId, 'Test Run', $now);

        // Drive the run to the requested transitional status through legal transitions.
        $run->start($now); // → starting
        if (Run::STATUS_ACTIVE === $status || Run::STATUS_RESTARTING === $status || Run::STATUS_IDLE === $status) {
            $run->markRunning('10.0.0.1', 9042, $now);
        }
        if (Run::STATUS_RESTARTING === $status) {
            $run->markRestarting($now);
        }

        $reflection = new \ReflectionProperty(Run::class, 'sessionId');
        $reflection->setValue($run, $sessionId);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function assertSessionStatus(string $sessionId, string $expected): void
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Session::class, $sessionId);
        self::assertInstanceOf(Session::class, $reloaded);
        self::assertSame($expected, $reloaded->getStatus());
    }

    private function assertRunStatus(string $runId, string $expected): void
    {
        $reloaded = $this->entityManager->find(Run::class, $runId);
        self::assertInstanceOf(Run::class, $reloaded);
        self::assertSame($expected, $reloaded->getStatus());
    }
}
