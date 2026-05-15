<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Domain\User;
use App\PersonalRuns\Domain\PersonalRun;
use App\PersonalRuns\Domain\PersonalRunParticipant;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use Doctrine\ORM\Tools\SchemaTool;

final class TraefikAndPublisherTokenTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Session::class),
            $this->entityManager->getClassMetadata(SessionSlot::class),
            $this->entityManager->getClassMetadata(Registration::class),
            $this->entityManager->getClassMetadata(ArchipelagoGame::class),
            $this->entityManager->getClassMetadata(PersonalRun::class),
            $this->entityManager->getClassMetadata(PersonalRunParticipant::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    // ─── Traefik config endpoint ──────────────────────────────────────────────

    public function testTraefikEndpointReturns401WithoutToken(): void
    {
        $this->client->request('GET', '/api/v1/internal/traefik');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTraefikEndpointReturns401WithWrongToken(): void
    {
        $this->client->request(
            'GET',
            '/api/v1/internal/traefik',
            [],
            [],
            ['HTTP_X_TRAEFIK_TOKEN' => 'wrong-token'],
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testTraefikEndpointReturnsEmptyConfigWhenNoRunningSessions(): void
    {
        $this->client->request(
            'GET',
            '/api/v1/internal/traefik',
            [],
            [],
            ['HTTP_X_TRAEFIK_TOKEN' => 'test-traefik-token'],
        );

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse();
        $http = $data['http'];
        self::assertIsArray($http);
        self::assertArrayHasKey('routers', $http);
        self::assertArrayHasKey('services', $http);
    }

    public function testTraefikEndpointExcludesNonRunningSessions(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistSessionInState(Session::STATUS_GENERATING);

        $this->client->request(
            'GET',
            '/api/v1/internal/traefik',
            [],
            [],
            ['HTTP_X_TRAEFIK_TOKEN' => 'test-traefik-token'],
        );

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse();
        $http = $data['http'];
        self::assertIsArray($http);
        $routers = (array) $http['routers'];
        self::assertArrayNotHasKey('run-'.$session->getId(), $routers);
    }

    public function testTraefikEndpointIncludesRunningSession(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistRunningSession();

        $this->client->request(
            'GET',
            '/api/v1/internal/traefik',
            [],
            [],
            ['HTTP_X_TRAEFIK_TOKEN' => 'test-traefik-token'],
        );

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse();
        $http = $data['http'];
        self::assertIsArray($http);
        $routerKey = 'run-'.$session->getId();
        $routers = (array) $http['routers'];
        self::assertArrayHasKey($routerKey, $routers);
        $routerData = (array) $routers[$routerKey];
        self::assertSame(
            sprintf('Host(`%s.ws.archilan.fr`)', $session->getId()),
            $routerData['rule'],
        );
        self::assertSame(['websecure'], $routerData['entryPoints']);

        $services = (array) $http['services'];
        self::assertArrayHasKey($routerKey, $services);
        $serviceData = (array) $services[$routerKey];
        $lb = (array) $serviceData['loadBalancer'];
        $servers = (array) $lb['servers'];
        $firstServer = (array) $servers[0];
        self::assertSame('http://runner-local:38281', $firstServer['url']);
    }

    public function testTraefikEndpointHandlesMultipleRunningSessions(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session1 = $this->persistRunningSession();

        $admin2 = $this->createUser('admin2@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin2');
        $this->loginAs($admin2);
        $session2 = $this->persistRunningSession();

        $this->client->request(
            'GET',
            '/api/v1/internal/traefik',
            [],
            [],
            ['HTTP_X_TRAEFIK_TOKEN' => 'test-traefik-token'],
        );

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse();
        $http = $data['http'];
        self::assertIsArray($http);
        $routers = (array) $http['routers'];
        self::assertArrayHasKey('run-'.$session1->getId(), $routers);
        self::assertArrayHasKey('run-'.$session2->getId(), $routers);
    }

    // ─── Publisher token endpoint ─────────────────────────────────────────────

    public function testPublisherTokenReturns401WithoutSecret(): void
    {
        $session = $this->persistSessionInState(Session::STATUS_RUNNING);

        $this->client->request('GET', sprintf('/api/v1/internal/sessions/%s/publisher-token', $session->getId()));

        self::assertResponseStatusCodeSame(401);
    }

    public function testPublisherTokenReturns401WithWrongSecret(): void
    {
        $session = $this->persistSessionInState(Session::STATUS_RUNNING);

        $this->client->request(
            'GET',
            sprintf('/api/v1/internal/sessions/%s/publisher-token', $session->getId()),
            [],
            [],
            ['HTTP_X_INTERNAL_SECRET' => 'wrong-secret'],
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testPublisherTokenReturns404ForUnknownSession(): void
    {
        $this->client->request(
            'GET',
            '/api/v1/internal/sessions/no-such-session/publisher-token',
            [],
            [],
            ['HTTP_X_INTERNAL_SECRET' => 'test-runner-secret'],
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testPublisherTokenReturnsTokenForExistingSession(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session = $this->persistRunningSession();

        $this->client->request(
            'GET',
            sprintf('/api/v1/internal/sessions/%s/publisher-token', $session->getId()),
            [],
            [],
            ['HTTP_X_INTERNAL_SECRET' => 'test-runner-secret'],
        );

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        $data = $response['data'];
        self::assertArrayHasKey('token', $data);
        self::assertArrayHasKey('expires_at', $data);
        self::assertIsString($data['token']);
        self::assertNotEmpty($data['token']);
        // expires_at should be approx 1h from now
        $expiresAtRaw = $data['expires_at'];
        self::assertIsString($expiresAtRaw);
        $expiresAt = new \DateTimeImmutable($expiresAtRaw);
        $diff = $expiresAt->getTimestamp() - time();
        self::assertGreaterThan(3500, $diff);
        self::assertLessThanOrEqual(3600, $diff);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createAdmin(): User
    {
        return $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
    }

    private function persistSessionInState(string $targetStatus): Session
    {
        $session = Session::create(bin2hex(random_bytes(8)), 'evt-001', new \DateTimeImmutable());
        $this->entityManager->persist($session);
        $this->entityManager->flush();
        $id = $session->getId();

        $path = $this->transitionPath($targetStatus);
        foreach ($path as $status) {
            $this->patchStatus($id, $status);
        }

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Session::class, $id);
        self::assertInstanceOf(Session::class, $refreshed);

        return $refreshed;
    }

    private function persistRunningSession(): Session
    {
        return $this->persistSessionInState(Session::STATUS_RUNNING);
    }

    private function patchStatus(
        string $sessionId,
        string $status,
        string $host = 'runner-local',
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

    /**
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
        ];

        return $paths[$target] ?? [];
    }
}
