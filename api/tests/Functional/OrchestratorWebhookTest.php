<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\PersonalRuns\Domain\Run;
use App\Sessions\Domain\Session;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyTemplate;

final class OrchestratorWebhookTest extends FunctionalTestCase
{
    private const WEBHOOK_SECRET = 'test-orchestrateur-secret';
    private const WEBHOOK_URL = '/api/v1/internal/orchestrateur/webhook';

    protected function setUp(): void
    {
        parent::setUp();
    }

    // ─── Auth ─────────────────────────────────────────────────────────────────

    public function testWebhookRequiresValidSignature(): void
    {
        $body = json_encode(['event' => 'session.generated', 'sessionId' => 'sess-1'], JSON_THROW_ON_ERROR);

        // No header → 401
        $this->client->request('POST', self::WEBHOOK_URL, [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        self::assertResponseStatusCodeSame(401);

        // Wrong secret → 401
        $badSig = 'sha256='.hash_hmac('sha256', $body, 'wrong-secret');
        $this->client->request('POST', self::WEBHOOK_URL, [], [], [
            'HTTP_X_SIGNATURE_256' => $badSig,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
        self::assertResponseStatusCodeSame(401);

        // Raw HMAC without "sha256=" prefix → 401 (prefix required by convention)
        $rawHmac = hash_hmac('sha256', $body, self::WEBHOOK_SECRET);
        $this->client->request('POST', self::WEBHOOK_URL, [], [], [
            'HTTP_X_SIGNATURE_256' => $rawHmac,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
        self::assertResponseStatusCodeSame(401);
    }

    // ─── session.generated ────────────────────────────────────────────────────

    public function testSessionGeneratedTransitionsToGenerated(): void
    {
        $session = $this->createSessionInStatus(Session::STATUS_GENERATING);

        $this->sendWebhook(['event' => 'session.generated', 'sessionId' => $session->getId()]);

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $refreshed);
        self::assertSame(Session::STATUS_GENERATED, $refreshed->getStatus());
    }

    public function testSessionGeneratedReturns404ForUnknownSession(): void
    {
        $this->sendWebhook(['event' => 'session.generated', 'sessionId' => 'no-such-session']);

        self::assertResponseStatusCodeSame(404);
    }

    // ─── session.crashed ──────────────────────────────────────────────────────

    public function testSessionCrashedTransitionsToCrashed(): void
    {
        $session = $this->createSessionInStatus(Session::STATUS_RUNNING);

        $this->sendWebhook(['event' => 'session.crashed', 'sessionId' => $session->getId()]);

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $refreshed);
        self::assertSame(Session::STATUS_CRASHED, $refreshed->getStatus());
    }

    public function testSessionCrashedFromGeneratingFailsAndResetsRun(): void
    {
        // Story 17.11: a generation crash must not leave the session on "generating"
        // and the personal run on "starting" forever.
        $session = $this->createSessionInStatus(Session::STATUS_GENERATING);
        $now = new \DateTimeImmutable();
        $run = Run::create('owner-1', 'Ma partie', $now);
        $run->start($now);
        $run->setSessionId($session->getId());
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->sendWebhook(['event' => 'session.crashed', 'sessionId' => $session->getId(), 'error' => 'generate boom']);

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();

        $refreshedSession = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $refreshedSession);
        self::assertSame(Session::STATUS_FAILED, $refreshedSession->getStatus());
        self::assertNotNull($refreshedSession->getValidationErrors());

        $refreshedRun = $this->entityManager->find(Run::class, $run->getId());
        self::assertInstanceOf(Run::class, $refreshedRun);
        self::assertSame(Run::STATUS_DRAFT, $refreshedRun->getStatus());
    }

    public function testSessionCrashedFromGeneratingReachesFailedWithoutRun(): void
    {
        $session = $this->createSessionInStatus(Session::STATUS_GENERATING);

        $this->sendWebhook(['event' => 'session.crashed', 'sessionId' => $session->getId()]);

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $refreshed);
        self::assertSame(Session::STATUS_FAILED, $refreshed->getStatus());
    }

    // ─── weekly-gen-* generator sessions ──────────────────────────────────────

    public function testWeeklyGenGeneratedMarksRunLaunchable(): void
    {
        $run = $this->createWeeklyRun();
        self::assertNull($run->getGeneratedOutputKey());

        $key = 'sessions/weekly-gen-'.$run->getId().'/output/AP_1.zip';
        $this->sendWebhook([
            'event' => 'session.generated',
            'sessionId' => 'weekly-gen-'.$run->getId(),
            'outputKey' => $key,
        ]);

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(WeeklyRun::class, $run->getId());
        self::assertInstanceOf(WeeklyRun::class, $refreshed);
        self::assertSame($key, $refreshed->getGeneratedOutputKey());
    }

    public function testWeeklyGenGeneratedIsIdempotent(): void
    {
        $run = $this->createWeeklyRun();
        $sessionId = 'weekly-gen-'.$run->getId();
        $firstKey = 'sessions/'.$sessionId.'/output/first.zip';

        $this->sendWebhook(['event' => 'session.generated', 'sessionId' => $sessionId, 'outputKey' => $firstKey]);
        self::assertResponseIsSuccessful();

        // A duplicate/retried webhook with a different key must not overwrite.
        $this->sendWebhook(['event' => 'session.generated', 'sessionId' => $sessionId, 'outputKey' => 'sessions/'.$sessionId.'/output/second.zip']);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(WeeklyRun::class, $run->getId());
        self::assertInstanceOf(WeeklyRun::class, $refreshed);
        self::assertSame($firstKey, $refreshed->getGeneratedOutputKey());
    }

    public function testWeeklyGenCrashedLeavesRunNotLaunchable(): void
    {
        $run = $this->createWeeklyRun();

        $this->sendWebhook([
            'event' => 'session.crashed',
            'sessionId' => 'weekly-gen-'.$run->getId(),
            'error' => 'generation boom',
        ]);

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(WeeklyRun::class, $run->getId());
        self::assertInstanceOf(WeeklyRun::class, $refreshed);
        self::assertNull($refreshed->getGeneratedOutputKey());
    }

    // ─── Unknown event ────────────────────────────────────────────────────────

    public function testUnknownEventReturns200(): void
    {
        $this->sendWebhook(['event' => 'session.something-new', 'sessionId' => 'any-id']);

        self::assertResponseIsSuccessful();
    }

    public function testMissingSessionIdReturns400(): void
    {
        $this->sendWebhook(['event' => 'session.generated']);

        self::assertResponseStatusCodeSame(400);
    }

    // ─── session.ready ────────────────────────────────────────────────────────

    public function testSessionReadyTransitionsToRunning(): void
    {
        // Advance to LAUNCHING via admin launch endpoint (which pre-stores credentials)
        $session = $this->createSessionInStatus(Session::STATUS_GENERATED);

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/launch', $session->getId()));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $launching = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $launching);
        self::assertSame(Session::STATUS_LAUNCHING, $launching->getStatus());

        $this->sendWebhook(['event' => 'session.ready', 'sessionId' => $session->getId(), 'port' => 38281]);

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $refreshed);
        self::assertSame(Session::STATUS_RUNNING, $refreshed->getStatus());
        self::assertSame(38281, $refreshed->getPort());
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createSessionInStatus(string $status): Session
    {
        $now = new \DateTimeImmutable();
        $session = Session::create(bin2hex(random_bytes(8)), 'evt-001', $now);

        $path = [
            Session::STATUS_VALIDATING,
            Session::STATUS_READY,
            Session::STATUS_GENERATING,
            Session::STATUS_GENERATED,
            Session::STATUS_LAUNCHING,
            Session::STATUS_RUNNING,
        ];

        foreach ($path as $step) {
            if (Session::STATUS_RUNNING === $step) {
                $session->transition($step, $now, 'localhost', 38281, 'test-pass');
            } else {
                $session->transition($step, $now);
            }
            if ($step === $status) {
                break;
            }
        }

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    private function createWeeklyRun(): WeeklyRun
    {
        $now = new \DateTimeImmutable('2026-05-18T00:00:00+00:00');
        $game = $this->createGame('Archipelago', 'archipelago');

        $template = new WeeklyTemplate(
            id: bin2hex(random_bytes(8)),
            gameId: $game->getId(),
            yamlConfig: "name: ArchiLAN\ngame: Archipelago\n",
            name: 'Weekly',
            maxAttempts: null,
            isActive: true,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->entityManager->persist($template);

        $run = new WeeklyRun(
            id: bin2hex(random_bytes(8)),
            templateId: $template->getId(),
            weekYear: 2026,
            weekNumber: 20,
            seed: 'archilan-weekly-2026-20',
            status: WeeklyRun::STATUS_ACTIVE,
            startedAt: $now,
            createdAt: $now,
        );
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    /** @param array<string, mixed> $payload */
    private function sendWebhook(array $payload): void
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $body, self::WEBHOOK_SECRET);

        $this->client->request('POST', self::WEBHOOK_URL, [], [], [
            'HTTP_X_SIGNATURE_256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }
}
