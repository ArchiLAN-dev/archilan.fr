<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\PersonalRuns\Application\Message\LaunchPersonalRunJob;
use App\PersonalRuns\Application\Message\StopPersonalRunJob;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipant;
use App\Sessions\Application\Message\ArchiveRunJob;
use App\Sessions\Domain\Session;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PersonalRunLifecycleTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    // ─── Start ───────────────────────────────────────────────────────────────

    public function testStartDraftRunReturns202AndDispatchesJob(): void
    {
        $user = $this->createUser('alice@example.org');
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $run = $this->createRunWithGames($user->getId(), [['gameId' => $game->getId()]]);
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/start');

        self::assertResponseStatusCodeSame(202);
        $data = $this->responseData();
        self::assertSame($run->getId(), $data['runId']);
        self::assertSame(Run::STATUS_STARTING, $data['status']);

        // Password stored on run
        $this->entityManager->refresh($run);
        self::assertSame(Run::STATUS_STARTING, $run->getStatus());
        self::assertNotNull($run->getConnectionPassword());
        self::assertSame(16, strlen($run->getConnectionPassword()));

        // Job dispatched to run_server queue
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');
        $sent = $transport->getSent();
        $jobs = array_values(array_filter($sent, static fn ($e) => $e->getMessage() instanceof LaunchPersonalRunJob));
        self::assertCount(1, $jobs);
        $message = $jobs[0]->getMessage();
        self::assertInstanceOf(LaunchPersonalRunJob::class, $message);
        self::assertSame($run->getId(), $message->personalRunId);
    }

    public function testStartAlreadyStartingReturns422(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunInStatus($user->getId(), Run::STATUS_STARTING);
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/start');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('run_already_active', $this->errorCode());
    }

    public function testStartAlreadyActiveReturns422(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunInStatus($user->getId(), Run::STATUS_ACTIVE);
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/start');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('run_already_active', $this->errorCode());
    }

    public function testStartUnauthenticatedReturns401(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunInStatus($user->getId(), Run::STATUS_DRAFT);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/start');

        self::assertResponseStatusCodeSame(401);
    }

    public function testStartWithoutGameConfigReturns422(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunInStatus($user->getId(), Run::STATUS_DRAFT);
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/start');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('games_required', $this->errorCode());
    }

    // ─── Callback /running ────────────────────────────────────────────────────

    public function testCallbackRunningTransitionsToActive(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunInStatus($user->getId(), Run::STATUS_STARTING);

        $this->sendCallback('/api/v1/runs/'.$run->getId().'/running', [
            'connectionHost' => 'runner.example.com',
            'connectionPort' => 38281,
        ]);

        self::assertResponseIsSuccessful();
        $data = $this->responseData();
        self::assertSame($run->getId(), $data['runId']);
        self::assertSame(Run::STATUS_ACTIVE, $data['status']);

        $this->entityManager->refresh($run);
        self::assertSame(Run::STATUS_ACTIVE, $run->getStatus());
        self::assertSame('runner.example.com', $run->getConnectionHost());
        self::assertSame(38281, $run->getConnectionPort());
    }

    public function testCallbackRunningRequiresSecret(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunInStatus($user->getId(), Run::STATUS_STARTING);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/running', [
            'connectionHost' => 'runner.example.com',
            'connectionPort' => 38281,
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testCallbackRunningRejectsNonStartingRun(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunInStatus($user->getId(), Run::STATUS_DRAFT);

        $this->sendCallback('/api/v1/runs/'.$run->getId().'/running', [
            'connectionHost' => 'runner.example.com',
            'connectionPort' => 38281,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_run_status', $this->errorCode());

        $this->entityManager->refresh($run);
        self::assertSame(Run::STATUS_DRAFT, $run->getStatus());
        self::assertNull($run->getConnectionHost());
        self::assertNull($run->getConnectionPort());
    }

    // ─── Stop ────────────────────────────────────────────────────────────────

    public function testStopActiveRunReturns202AndDispatchesJob(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunInStatus($user->getId(), Run::STATUS_ACTIVE);
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/stop');

        self::assertResponseStatusCodeSame(202);
        $data = $this->responseData();
        self::assertSame($run->getId(), $data['runId']);
        self::assertSame(Run::STATUS_STOPPING, $data['status']);

        // Job dispatched to run_server queue
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');
        $sent = $transport->getSent();
        $jobs = array_values(array_filter($sent, static fn ($e) => $e->getMessage() instanceof StopPersonalRunJob));
        self::assertCount(1, $jobs);
        $message = $jobs[0]->getMessage();
        self::assertInstanceOf(StopPersonalRunJob::class, $message);
        self::assertSame($run->getId(), $message->personalRunId);
    }

    public function testStopNonActiveRunReturns422(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunInStatus($user->getId(), Run::STATUS_IDLE);
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/stop');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('run_not_active', $this->errorCode());
    }

    // ─── Finish (owner) ───────────────────────────────────────────────────────

    public function testFinishActiveRunFinishesSessionAndCompletesRun(): void
    {
        $user = $this->createUser('alice@example.org');
        $session = $this->createRunningSession('sess-finish-1', 'evt-finish-1');
        $run = $this->createActiveRunWithSession($user->getId(), $session->getId());
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/finish');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(Run::STATUS_COMPLETED, $this->responseData()['status']);

        $this->entityManager->refresh($run);
        self::assertSame(Run::STATUS_COMPLETED, $run->getStatus());
        $this->entityManager->refresh($session);
        self::assertSame(Session::STATUS_FINISHED, $session->getStatus());

        // The archive job (which snapshots the bridge's real goal/check state) is dispatched.
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');
        $archiveJobs = array_filter(
            array_map(static fn ($e) => $e->getMessage(), $transport->getSent()),
            static fn ($m) => $m instanceof ArchiveRunJob,
        );
        self::assertCount(1, $archiveJobs);
    }

    public function testFinishNonOwnerReturns403(): void
    {
        $owner = $this->createUser('owner@example.org');
        $intruder = $this->createUser('intruder@example.org');
        $session = $this->createRunningSession('sess-finish-2', 'evt-finish-2');
        $run = $this->createActiveRunWithSession($owner->getId(), $session->getId());
        $this->loginAs($intruder);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/finish');

        self::assertResponseStatusCodeSame(403);
    }

    public function testFinishNonActiveRunReturns409(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunInStatus($user->getId(), Run::STATUS_IDLE);
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/finish');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('run_not_active', $this->errorCode());
    }

    public function testFinishUnauthenticatedReturns401(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunInStatus($user->getId(), Run::STATUS_ACTIVE);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/finish');

        self::assertResponseStatusCodeSame(401);
    }

    // ─── Callback /stopped ────────────────────────────────────────────────────

    public function testCallbackStoppedTransitionsToIdle(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunInStatus($user->getId(), Run::STATUS_STOPPING);
        // Give it connection fields to verify they are cleared
        $run->markRunning('runner.example.com', 38281, new \DateTimeImmutable());
        $run->stop(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->sendCallback('/api/v1/runs/'.$run->getId().'/stopped', []);

        self::assertResponseIsSuccessful();
        $data = $this->responseData();
        self::assertSame(Run::STATUS_IDLE, $data['status']);

        $this->entityManager->refresh($run);
        self::assertSame(Run::STATUS_IDLE, $run->getStatus());
        self::assertNull($run->getConnectionHost());
        self::assertNull($run->getConnectionPort());
        self::assertNull($run->getConnectionPassword());
    }

    public function testCallbackStoppedRequiresSecret(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunInStatus($user->getId(), Run::STATUS_STOPPING);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/stopped', []);

        self::assertResponseStatusCodeSame(401);
    }

    public function testCallbackStoppedRejectsNonStoppingRun(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunInStatus($user->getId(), Run::STATUS_ACTIVE);
        $run->markRunning('runner.example.com', 38281, new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->sendCallback('/api/v1/runs/'.$run->getId().'/stopped', []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_run_status', $this->errorCode());

        $this->entityManager->refresh($run);
        self::assertSame(Run::STATUS_ACTIVE, $run->getStatus());
        self::assertSame('runner.example.com', $run->getConnectionHost());
        self::assertSame(38281, $run->getConnectionPort());
    }

    // ─── GET connection details ───────────────────────────────────────────────

    public function testGetConnectionDetailsWhenActiveAreNonNull(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunInStatus($user->getId(), Run::STATUS_ACTIVE);
        $run->markRunning('runner.example.com', 38281, new \DateTimeImmutable());
        // Simulate password set at start time
        $reflection = new \ReflectionProperty(Run::class, 'connectionPassword');
        $reflection->setValue($run, 'deadbeef12345678');
        $this->entityManager->flush();

        $this->loginAs($user);
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId());

        self::assertResponseIsSuccessful();
        $data = $this->responseData();
        self::assertSame('runner.example.com', $data['connectionHost']);
        self::assertSame(38281, $data['connectionPort']);
        self::assertSame('deadbeef12345678', $data['connectionPassword']);
    }

    public function testGetConnectionDetailsWhenIdleAreNull(): void
    {
        $user = $this->createUser('alice@example.org');
        $run = $this->createRunInStatus($user->getId(), Run::STATUS_IDLE);
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId());

        self::assertResponseIsSuccessful();
        $data = $this->responseData();
        self::assertNull($data['connectionHost']);
        self::assertNull($data['connectionPort']);
        self::assertNull($data['connectionPassword']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** @param list<array{gameId: string}> $games */
    private function createRunWithGames(string $ownerId, array $games): Run
    {
        $now = new \DateTimeImmutable('2026-05-12T10:00:00+00:00');
        $run = Run::create($ownerId, 'Test Run', $now);
        $run->configureGames($games, $now);
        $this->entityManager->persist($run);

        $participant = RunParticipant::create($run->getId(), $ownerId, $now);
        $slots = array_map(
            static fn (array $g): array => ['slotId' => bin2hex(random_bytes(8)), 'gameId' => $g['gameId']],
            $games,
        );
        $participant->replaceSlots($slots);
        $this->entityManager->persist($participant);

        $this->entityManager->flush();

        return $run;
    }

    private function createRunInStatus(string $ownerId, string $status): Run
    {
        $now = new \DateTimeImmutable('2026-05-12T10:00:00+00:00');
        $run = Run::create($ownerId, 'Test Run', $now);

        $reflection = new \ReflectionProperty(Run::class, 'status');
        $reflection->setValue($run, $status);

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function createActiveRunWithSession(string $ownerId, string $sessionId): Run
    {
        $now = new \DateTimeImmutable('2026-05-12T10:00:00+00:00');
        $run = Run::create($ownerId, 'Test Run', $now);
        $run->setSessionId($sessionId);
        (new \ReflectionProperty(Run::class, 'status'))->setValue($run, Run::STATUS_ACTIVE);

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function createRunningSession(string $id, string $eventId): Session
    {
        $now = new \DateTimeImmutable();
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

    /** @param array<string, mixed> $payload */
    private function sendCallback(string $url, array $payload): void
    {
        $this->client->request(
            'POST',
            $url,
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function responseData(): array
    {
        $decoded = $this->decodedResponse();
        $data = $decoded['data'] ?? null;
        self::assertIsArray($data);

        return $this->stringKeyedArray($data);
    }

    private function errorCode(): string
    {
        $decoded = $this->decodedResponse();
        $error = $decoded['error'] ?? null;
        self::assertIsArray($error);
        $code = $error['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedResponse(): array
    {
        $content = $this->client->getResponse()->getContent() ?: '';
        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $this->stringKeyedArray($decoded);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }
}
