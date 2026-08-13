<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Sessions\Domain\Entity\Session;

final class TraefikAndPublisherTokenTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
        $tcp = $data['tcp'];
        self::assertIsArray($tcp);
        self::assertArrayHasKey('routers', $tcp);
        self::assertArrayHasKey('services', $tcp);
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
        $tcp = $data['tcp'];
        self::assertIsArray($tcp);
        $routers = (array) $tcp['routers'];
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
        $tcp = $data['tcp'];
        self::assertIsArray($tcp);
        $routerKey = 'run-'.$session->getId();
        $routers = (array) $tcp['routers'];
        self::assertArrayHasKey($routerKey, $routers);
        $routerData = (array) $routers[$routerKey];
        // SNI joker : le port identifie le run, il n'y a qu'un backend derrière l'entrypoint.
        self::assertSame('HostSNI(`*`)', $routerData['rule']);
        self::assertSame(['ap-38281'], $routerData['entryPoints']);

        $services = (array) $tcp['services'];
        self::assertArrayHasKey($routerKey, $services);
        $serviceData = (array) $services[$routerKey];
        $lb = (array) $serviceData['loadBalancer'];
        $servers = (array) $lb['servers'];
        $firstServer = (array) $servers[0];
        // Adresse interne du conteneur, pas le port publié sur l'hôte (story 37.3).
        self::assertSame(
            sprintf('ap-server-%s:38281', $session->getId()),
            $firstServer['address'],
        );
    }

    /**
     * Le certificat est ce que 9.11 avait oublié : sans certresolver ni domaine explicite, Traefik
     * sert son certificat auto-signé, et un navigateur refuse la connexion WebSocket sans le
     * moindre interstitiel. La panne est invisible côté serveur, d'où ce test.
     */
    public function testTraefikRouterRequestsARealCertificateForThePublicHost(): void
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
        $tcp = $data['tcp'];
        self::assertIsArray($tcp);
        $routers = (array) $tcp['routers'];
        $routerData = (array) $routers['run-'.$session->getId()];
        $tls = (array) $routerData['tls'];

        self::assertSame('https', $tls['certResolver']);
        // RUNNER_PUBLIC_HOST vaut « localhost » dans api/.env.test.
        self::assertSame([['main' => 'localhost']], $tls['domains']);
    }

    public function testTraefikRoutersUseOneEntrypointPerPort(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);
        $session1 = $this->persistSessionInState(Session::STATUS_RUNNING, 35042);

        $admin2 = $this->createUser('admin2@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin2');
        $this->loginAs($admin2);
        $session2 = $this->persistSessionInState(Session::STATUS_RUNNING, 35043);

        $this->client->request(
            'GET',
            '/api/v1/internal/traefik',
            [],
            [],
            ['HTTP_X_TRAEFIK_TOKEN' => 'test-traefik-token'],
        );

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse();
        $tcp = $data['tcp'];
        self::assertIsArray($tcp);
        $routers = (array) $tcp['routers'];

        $router1 = (array) $routers['run-'.$session1->getId()];
        $router2 = (array) $routers['run-'.$session2->getId()];
        self::assertSame(['ap-35042'], $router1['entryPoints']);
        self::assertSame(['ap-35043'], $router2['entryPoints']);
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
        $tcp = $data['tcp'];
        self::assertIsArray($tcp);
        $routers = (array) $tcp['routers'];
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

    public function testPublisherTokenReturns200ForAnyRunIdWithValidSecret(): void
    {
        // Session existence is no longer validated - weekly entries use this endpoint too.
        $this->client->request(
            'GET',
            '/api/v1/internal/sessions/no-such-session/publisher-token',
            [],
            [],
            ['HTTP_X_INTERNAL_SECRET' => 'test-runner-secret'],
        );

        self::assertResponseStatusCodeSame(200);
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

    private function persistSessionInState(string $targetStatus, ?int $port = null): Session
    {
        $session = Session::create(bin2hex(random_bytes(8)), 'evt-001', new \DateTimeImmutable());
        $this->entityManager->persist($session);
        $this->entityManager->flush();
        $id = $session->getId();

        $path = $this->transitionPath($targetStatus);
        foreach ($path as $status) {
            if (null !== $port && Session::STATUS_RUNNING === $status) {
                $this->patchStatus($id, $status, port: $port);
                continue;
            }
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
