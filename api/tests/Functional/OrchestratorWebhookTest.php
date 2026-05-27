<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use Doctrine\ORM\Tools\SchemaTool;

final class OrchestratorWebhookTest extends FunctionalTestCase
{
    private const WEBHOOK_SECRET = 'test-orchestrateur-secret';
    private const WEBHOOK_URL = '/api/v1/internal/orchestrateur/webhook';

    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Session::class),
            $this->entityManager->getClassMetadata(SessionSlot::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
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
