<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Communications\Application\SessionPausedWithoutSaveMessage;
use App\PersonalRuns\Domain\PersonalRun;
use App\Sessions\Application\Message\PauseRunJob;
use App\Sessions\Application\ScheduledTask\InactivityWatchdogHandler;
use App\Sessions\Application\ScheduledTask\InactivityWatchdogMessage;
use App\Sessions\Domain\Session;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class InactivityWatchdogTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $metadata = [
            $this->entityManager->getClassMetadata(Session::class),
            $this->entityManager->getClassMetadata(PersonalRun::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        /** @var InMemoryTransport $runServerTransport */
        $runServerTransport = self::getContainer()->get('messenger.transport.run_server');
        $runServerTransport->reset();

        /** @var InMemoryTransport $asyncTransport */
        $asyncTransport = self::getContainer()->get('messenger.transport.async');
        $asyncTransport->reset();
    }

    // ─── Watchdog handler tests ───────────────────────────────────────────────

    public function testSessionBelowThresholdIsNotPaused(): void
    {
        $session = $this->createRunningSessionWithActivity(new \DateTimeImmutable('-30 minutes'));

        $this->runWatchdog();

        $pauseJobs = $this->getPauseJobsForSession($session->getId());
        self::assertCount(0, $pauseJobs);
    }

    public function testSessionAboveThresholdGetsPauseJobDispatched(): void
    {
        $session = $this->createRunningSessionWithActivity(new \DateTimeImmutable('-2 hours'));

        $this->runWatchdog();

        $pauseJobs = $this->getPauseJobsForSession($session->getId());
        self::assertCount(1, $pauseJobs);
        self::assertSame($session->getId(), $pauseJobs[0]->sessionId);
    }

    public function testSessionWithNullActivityAndOldStartIsDispatched(): void
    {
        $session = $this->createRunningSessionWithActivity(null, new \DateTimeImmutable('-2 hours'));

        $this->runWatchdog();

        $pauseJobs = $this->getPauseJobsForSession($session->getId());
        self::assertCount(1, $pauseJobs);
    }

    public function testSessionWithinGracePeriodIsNotDispatched(): void
    {
        // started_at < 60s ago → grace period → skip
        $session = $this->createRunningSessionWithActivity(null, new \DateTimeImmutable('-30 seconds'));

        $this->runWatchdog();

        $pauseJobs = $this->getPauseJobsForSession($session->getId());
        self::assertCount(0, $pauseJobs);
    }

    public function testDefaultThresholdIs3600Seconds(): void
    {
        // 3601s of inactivity → should be paused with default 3600s threshold
        $session = $this->createRunningSessionWithActivity(new \DateTimeImmutable('-3601 seconds'));

        $this->runWatchdog();

        $pauseJobs = $this->getPauseJobsForSession($session->getId());
        self::assertCount(1, $pauseJobs);

        // 3599s → should NOT be paused
        $recentSession = $this->createRunningSessionWithActivity(new \DateTimeImmutable('-3599 seconds'));

        $this->resetTransports();
        $this->runWatchdog();

        $pauseJobsRecent = $this->getPauseJobsForSession($recentSession->getId());
        self::assertCount(0, $pauseJobsRecent);
    }

    // ─── /paused callback endpoint tests ─────────────────────────────────────

    public function testPausedCallbackWithFailedSaveSetsFlag(): void
    {
        $session = $this->createRunningSession();

        $this->client->request(
            'POST',
            sprintf('/api/v1/sessions/%s/paused', $session->getId()),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['saveKey' => null, 'failedSave' => true], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $reloaded);
        self::assertSame(Session::STATUS_IDLE, $reloaded->getStatus());
        self::assertTrue($reloaded->isPausedWithoutSave());
        self::assertNull($reloaded->getLastSaveKey());

        /** @var InMemoryTransport $asyncTransport */
        $asyncTransport = self::getContainer()->get('messenger.transport.async');
        $sent = array_values(array_filter(
            $asyncTransport->getSent(),
            static fn ($e) => $e->getMessage() instanceof SessionPausedWithoutSaveMessage,
        ));
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SessionPausedWithoutSaveMessage::class, $message);
        self::assertSame($session->getId(), $message->sessionId);
    }

    public function testPausedCallbackAlreadyIdleReturns200Idempotent(): void
    {
        $session = $this->createIdleSession();

        $this->client->request(
            'POST',
            sprintf('/api/v1/sessions/%s/paused', $session->getId()),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['saveKey' => null, 'failedSave' => false], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testPausedCallbackUnexpectedStatusReturns422(): void
    {
        // A stopped session is not running → unexpected status
        $session = $this->createStoppedSession();

        $this->client->request(
            'POST',
            sprintf('/api/v1/sessions/%s/paused', $session->getId()),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['saveKey' => null, 'failedSave' => false], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        $body = $this->decodedJsonResponse();
        $error = $body['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('unexpected_status', $error['code'] ?? null);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function runWatchdog(): void
    {
        /** @var InactivityWatchdogHandler $handler */
        $handler = self::getContainer()->get(InactivityWatchdogHandler::class);
        $handler(new InactivityWatchdogMessage());
    }

    /** @return list<PauseRunJob> */
    private function getPauseJobsForSession(string $sessionId): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');

        return array_values(array_filter(
            array_map(static fn ($e) => $e->getMessage(), $transport->getSent()),
            static fn ($msg) => $msg instanceof PauseRunJob && $msg->sessionId === $sessionId,
        ));
    }

    private function resetTransports(): void
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');
        $transport->reset();

        /** @var InMemoryTransport $asyncTransport */
        $asyncTransport = self::getContainer()->get('messenger.transport.async');
        $asyncTransport->reset();
    }

    private function createRunningSession(): Session
    {
        $session = Session::create(bin2hex(random_bytes(16)), 'evt-001', new \DateTimeImmutable('-3 hours'));
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

    private function createRunningSessionWithActivity(?\DateTimeImmutable $lastActivityAt, ?\DateTimeImmutable $startedAt = null): Session
    {
        $startedAt ??= new \DateTimeImmutable('-3 hours');
        $session = Session::create(bin2hex(random_bytes(16)), 'evt-001', $startedAt);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        foreach ([
            Session::STATUS_VALIDATING,
            Session::STATUS_READY,
            Session::STATUS_GENERATING,
            Session::STATUS_GENERATED,
            Session::STATUS_LAUNCHING,
        ] as $status) {
            $session->transition($status, $startedAt);
        }
        $session->transition(Session::STATUS_RUNNING, $startedAt, '10.0.0.1', 9042, 'secret');

        if (null !== $lastActivityAt) {
            $session->recordActivity($lastActivityAt);
        }

        $this->entityManager->flush();

        return $session;
    }

    private function createIdleSession(): Session
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

    /** @return array<mixed> */
    private function decodedJsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
