<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use App\Sessions\Application\Message\ArchiveRunJob;
use App\Sessions\Application\Message\FetchLogsJob;
use App\Sessions\Application\Message\StopRunJob;
use App\Sessions\Domain\RunAuditLog;
use App\Sessions\Domain\Session;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class AdminServerCommandsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AuthSessionSigner $authSessionSigner;
    private MockHttpClient $httpClient;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $authSessionSigner = self::getContainer()->get(AuthSessionSigner::class);
        self::assertInstanceOf(AuthSessionSigner::class, $authSessionSigner);
        $this->authSessionSigner = $authSessionSigner;

        $httpClient = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $this->httpClient = $httpClient;

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Session::class),
            $this->entityManager->getClassMetadata(RunAuditLog::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testCommandForwardsToBridge(): void
    {
        $session = $this->createRunningSession('run-cmd-1', 'evt-001');
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->httpClient->setResponseFactory(new MockResponse('{"ok":true}', ['http_code' => 200]));

        $this->client->jsonRequest(
            'POST',
            sprintf('/api/v1/admin/sessions/%s/commands', $session->getId()),
            ['command' => '/hint AliceSlot ItemName'],
        );
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $responseData = $response['data'];
        self::assertIsArray($responseData);
        self::assertSame(true, $responseData['ok']);

        $log = $this->entityManager->getRepository(RunAuditLog::class)->findOneBy(['runId' => $session->getId()]);
        self::assertInstanceOf(RunAuditLog::class, $log);
        self::assertSame('command', $log->getAction());
        $logPayload = $log->getPayload();
        self::assertIsArray($logPayload);
        self::assertSame('/hint AliceSlot ItemName', $logPayload['command']);
    }

    public function testCommandReturns503WhenBridgeUnreachable(): void
    {
        $session = $this->createRunningSession('run-cmd-2', 'evt-001');
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->httpClient->setResponseFactory(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused');
        });

        $this->client->jsonRequest(
            'POST',
            sprintf('/api/v1/admin/sessions/%s/commands', $session->getId()),
            ['command' => '/hint Test Item'],
        );
        self::assertResponseStatusCodeSame(503);

        $response = $this->decodedJsonResponse();
        $errorData = $response['error'];
        self::assertIsArray($errorData);
        self::assertSame('bridge_unavailable', $errorData['code']);
    }

    public function testLogsFetchDispatchesFetchLogsJob(): void
    {
        $session = $this->createRunningSession('run-logs-1', 'evt-001');
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/sessions/%s/logs', $session->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $responseData = $response['data'];
        self::assertIsArray($responseData);
        self::assertSame('', $responseData['logs']);
        self::assertArrayHasKey('fetched_at', $responseData);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');
        $enveloped = iterator_to_array($transport->get(), false);
        self::assertCount(1, $enveloped);
        $firstMessage = $enveloped[0]->getMessage();
        self::assertInstanceOf(FetchLogsJob::class, $firstMessage);
        self::assertSame($session->getId(), $firstMessage->sessionId);
    }

    public function testLogsCallbackStoresOutput(): void
    {
        $session = $this->createRunningSession('run-logs-cb-1', 'evt-001');
        $logOutput = "[2026-05-06T10:00:00Z] Server started\n[2026-05-06T10:00:01Z] Player connected";

        $this->client->jsonRequest(
            'POST',
            sprintf('/api/v1/internal/sessions/%s/runner-callback', $session->getId()),
            ['status' => 'logs', 'output' => $logOutput],
            ['HTTP_X-Internal-Secret' => 'test-runner-secret'],
        );
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $responseData = $response['data'];
        self::assertIsArray($responseData);
        self::assertSame(true, $responseData['ok']);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $refreshed);
        self::assertSame($logOutput, $refreshed->getLastLogs());
    }

    public function testForceEndTransitionsToFinished(): void
    {
        $session = $this->createRunningSession('run-end-1', 'evt-001');
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/force-end', $session->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $responseData = $response['data'];
        self::assertIsArray($responseData);
        self::assertSame('finished', $responseData['status']);
        self::assertNotNull($responseData['finishedAt']);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');
        $enveloped = iterator_to_array($transport->get(), false);
        self::assertCount(2, $enveloped);

        $messages = array_map(static fn ($e) => $e->getMessage(), $enveloped);
        $stopJobs = array_filter($messages, static fn ($m) => $m instanceof StopRunJob);
        $archiveJobs = array_filter($messages, static fn ($m) => $m instanceof ArchiveRunJob);

        self::assertCount(1, $stopJobs);
        self::assertCount(1, $archiveJobs);

        $log = $this->entityManager->getRepository(RunAuditLog::class)->findOneBy(['runId' => $session->getId()]);
        self::assertInstanceOf(RunAuditLog::class, $log);
        self::assertSame('force_end', $log->getAction());
    }

    public function testForceEndReturns409WhenNotRunning(): void
    {
        $session = $this->createSession('run-end-2', 'evt-001');
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/force-end', $session->getId()));
        self::assertResponseStatusCodeSame(409);

        $response = $this->decodedJsonResponse();
        $errorData = $response['error'];
        self::assertIsArray($errorData);
        self::assertSame('session_not_running', $errorData['code']);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function createSession(string $id, string $eventId): Session
    {
        $session = Session::create($id, $eventId, new \DateTimeImmutable());
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
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

    private function createAdmin(): User
    {
        $now = new \DateTimeImmutable('2026-05-06T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            'admin@example.org',
            'admin@example.org',
            'Admin',
            'test-password-hash',
            ['ROLE_USER', 'ROLE_ADMIN'],
            $now, $now, $now,
        );
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function loginAs(User $user): void
    {
        $this->client->getCookieJar()->set(
            new Cookie(AuthSessionSigner::COOKIE_NAME, $this->authSessionSigner->sign($user->getId())),
        );
    }

    /** @return array<mixed> */
    private function decodedJsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
