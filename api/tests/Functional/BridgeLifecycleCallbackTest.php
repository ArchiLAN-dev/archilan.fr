<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Communications\Application\SessionRestartFailedMessage;
use App\PersonalRuns\Domain\PersonalRun;
use App\Sessions\Domain\Session;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Tests for the bridge-triggered lifecycle callbacks:
 *   POST /api/v1/internal/sessions/{id}/restarting  (idle → restarting)
 *   POST /api/v1/internal/sessions/{id}/restart-failed  (restarting → idle)
 */
final class BridgeLifecycleCallbackTest extends WebTestCase
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

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();
    }

    // ─── POST /restarting ────────────────────────────────────────────────────

    public function testRestartingTransitionsIdleToRestarting(): void
    {
        $session = $this->createIdleSession();

        $this->client->request(
            'POST',
            '/api/v1/internal/sessions/'.$session->getId().'/restarting',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $reloaded);
        self::assertSame(Session::STATUS_RESTARTING, $reloaded->getStatus());
    }

    public function testRestartingMissingTokenReturns401(): void
    {
        $session = $this->createIdleSession();

        $this->client->request(
            'POST',
            '/api/v1/internal/sessions/'.$session->getId().'/restarting',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testRestartingWrongTokenReturns401(): void
    {
        $session = $this->createIdleSession();

        $this->client->request(
            'POST',
            '/api/v1/internal/sessions/'.$session->getId().'/restarting',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer wrong-token', 'CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testRestartingUnknownSessionReturns404(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/internal/sessions/nonexistent-id/restarting',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testRestartingNonIdleSessionReturns409(): void
    {
        $session = $this->createRunningSession();

        $this->client->request(
            'POST',
            '/api/v1/internal/sessions/'.$session->getId().'/restarting',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        self::assertResponseStatusCodeSame(409);
    }

    public function testRestartingIdempotentWhenAlreadyRestarting(): void
    {
        $session = $this->createRestartingSession();

        $this->client->request(
            'POST',
            '/api/v1/internal/sessions/'.$session->getId().'/restarting',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        self::assertResponseStatusCodeSame(200);
    }

    // ─── POST /restart-failed ─────────────────────────────────────────────────

    public function testRestartFailedTransitionsRestartingToIdle(): void
    {
        $session = $this->createRestartingSession();

        $this->client->request(
            'POST',
            '/api/v1/internal/sessions/'.$session->getId().'/restart-failed',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $reloaded);
        self::assertSame(Session::STATUS_IDLE, $reloaded->getStatus());
        self::assertTrue($reloaded->hasRestartFailed());

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages = array_values(array_filter(
            array_map(static fn ($e) => $e->getMessage(), $transport->getSent()),
            static fn ($message) => $message instanceof SessionRestartFailedMessage,
        ));

        self::assertCount(1, $messages);
        self::assertInstanceOf(SessionRestartFailedMessage::class, $messages[0]);
        self::assertSame($session->getId(), $messages[0]->sessionId);
    }

    public function testRestartFailedMissingTokenReturns401(): void
    {
        $session = $this->createRestartingSession();

        $this->client->request(
            'POST',
            '/api/v1/internal/sessions/'.$session->getId().'/restart-failed',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testRestartFailedUnknownSessionReturns404(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/internal/sessions/nonexistent-id/restart-failed',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testRestartFailedWhenNotRestartingReturns409(): void
    {
        $session = $this->createRunningSession();

        $this->client->request(
            'POST',
            '/api/v1/internal/sessions/'.$session->getId().'/restart-failed',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        self::assertResponseStatusCodeSame(409);
    }

    public function testRestartFailedIdempotentWhenAlreadyIdle(): void
    {
        $session = $this->createIdleSession();

        $this->client->request(
            'POST',
            '/api/v1/internal/sessions/'.$session->getId().'/restart-failed',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        self::assertResponseStatusCodeSame(200);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

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

    private function createIdleSession(): Session
    {
        $session = $this->createRunningSession();
        $session->markIdle('sessions/abc/saves/save.apsave', false, new \DateTimeImmutable());
        $this->entityManager->flush();

        return $session;
    }

    private function createRestartingSession(): Session
    {
        $session = $this->createIdleSession();
        $session->markRestarting(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $session;
    }
}
