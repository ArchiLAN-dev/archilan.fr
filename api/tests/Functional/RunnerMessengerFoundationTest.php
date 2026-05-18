<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;
use App\Sessions\Application\Message\RestartRunJob;
use App\Sessions\Application\Message\RunHealthCheckJob;
use App\Sessions\Application\Message\StartRunJob;
use App\Sessions\Application\Message\StopRunJob;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use App\Shared\Application\Message\GenerateRunJob;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RunnerMessengerFoundationTest extends FunctionalTestCase
{
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

    // ─── Message class structure ──────────────────────────────────────────────

    public function testGenerateRunJobCarriesSessionIdAndPhase(): void
    {
        $job = new GenerateRunJob('sess-abc', 'validate');

        self::assertSame('sess-abc', $job->sessionId);
        self::assertSame('validate', $job->phase);
    }

    public function testStartRunJobCarriesSessionId(): void
    {
        $job = new StartRunJob('sess-abc');

        self::assertSame('sess-abc', $job->sessionId);
    }

    public function testStopRunJobCarriesSessionId(): void
    {
        $job = new StopRunJob('sess-abc', 38281, 5000);

        self::assertSame('sess-abc', $job->sessionId);
        self::assertSame(38281, $job->port);
        self::assertSame(5000, $job->bridgePort);
    }

    public function testRestartRunJobCarriesSessionId(): void
    {
        $job = new RestartRunJob('sess-abc', 38281, 5000, 'secret');

        self::assertSame('sess-abc', $job->sessionId);
        self::assertSame(38281, $job->port);
        self::assertSame(5000, $job->bridgePort);
        self::assertSame('secret', $job->password);
    }

    public function testRunHealthCheckJobCarriesFields(): void
    {
        $job = new RunHealthCheckJob('sess-abc', 38281, 5000, 2);

        self::assertSame('sess-abc', $job->sessionId);
        self::assertSame(38281, $job->port);
        self::assertSame(5000, $job->bridgePort);
        self::assertSame(2, $job->consecutiveFailures);
    }

    // ─── Message dispatch to queue ────────────────────────────────────────────

    public function testGenerateRunJobIsDispatchableToRunGenerationQueue(): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $bus->dispatch(new GenerateRunJob('sess-1', 'generate'));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_generation');
        self::assertCount(1, $transport->getSent());
        $envelope = $transport->getSent()[0];
        $message = $envelope->getMessage();
        self::assertInstanceOf(GenerateRunJob::class, $message);
        self::assertSame('sess-1', $message->sessionId);
    }

    public function testStartRunJobIsDispatchableToRunServerQueue(): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new StartRunJob('sess-2'));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');
        $sent = $transport->getSent();
        self::assertGreaterThanOrEqual(1, count($sent));

        $found = array_filter($sent, static fn ($e) => $e->getMessage() instanceof StartRunJob);
        self::assertCount(1, $found);
    }

    public function testStopAndRestartJobsRouteToRunServerQueue(): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new StopRunJob('sess-3', 38281, 5000));
        $bus->dispatch(new RestartRunJob('sess-3', 38281, 5000, 'secret'));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');
        $sent = $transport->getSent();

        $stopFound = array_filter($sent, static fn ($e) => $e->getMessage() instanceof StopRunJob);
        $restartFound = array_filter($sent, static fn ($e) => $e->getMessage() instanceof RestartRunJob);
        self::assertCount(1, $stopFound);
        self::assertCount(1, $restartFound);
    }

    public function testRunHealthCheckJobRoutesToRunServerQueue(): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new RunHealthCheckJob('sess-4', 38281, 5000, 0));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');
        $sent = $transport->getSent();
        $found = array_filter($sent, static fn ($e) => $e->getMessage() instanceof RunHealthCheckJob);
        self::assertCount(1, $found);
    }

    // ─── Runner callback endpoint ─────────────────────────────────────────────

    public function testRunnerCallbackRequiresInternalSecret(): void
    {
        $session = $this->createSession();

        // No header → 401
        $this->client->jsonRequest(
            'POST',
            sprintf('/api/v1/internal/sessions/%s/runner-callback', $session->getId()),
            ['status' => 'validating'],
        );
        self::assertResponseStatusCodeSame(401);

        // Wrong secret → 401
        $this->client->request(
            'POST',
            sprintf('/api/v1/internal/sessions/%s/runner-callback', $session->getId()),
            [],
            [],
            ['HTTP_X_INTERNAL_SECRET' => 'wrong', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['status' => 'validating'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(401);
    }

    public function testRunnerCallbackTransitionsSessionWithCorrectSecret(): void
    {
        $session = $this->createSession();

        $this->sendCallback($session->getId(), ['status' => 'validating', 'runner_id' => 'runner-1']);

        self::assertResponseIsSuccessful();
        $response = $this->jsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('validating', $data['status']);
    }

    public function testRunnerCallbackIncludesRunnerIdInPayloadButDoesNotBreak(): void
    {
        $session = $this->createSession();

        // runner_id is passed in payload but controller accepts extra fields gracefully
        $this->sendCallback($session->getId(), [
            'status' => 'validating',
            'runner_id' => 'runner-prod-01',
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testRunnerCallbackReturns404ForUnknownSession(): void
    {
        $this->sendCallback('no-such-session', ['status' => 'validating']);

        self::assertResponseStatusCodeSame(404);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createSession(): Session
    {
        $session = Session::create(bin2hex(random_bytes(8)), 'evt-001', new \DateTimeImmutable());
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
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

    /** @return array<mixed> */
    private function jsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
