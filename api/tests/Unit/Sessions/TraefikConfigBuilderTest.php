<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\Support\TraefikConfigBuilder;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class TraefikConfigBuilderTest extends TestCase
{
    public function testBuildReturnsEmptyObjectsWhenNoRunningSession(): void
    {
        $config = $this->buildWith([]);

        $tcp = $config['tcp'];
        self::assertIsArray($tcp);
        // Objets et non tableaux : Traefik refuse `"routers": []`.
        self::assertInstanceOf(\stdClass::class, $tcp['routers']);
        self::assertInstanceOf(\stdClass::class, $tcp['services']);
    }

    public function testRouterUsesTheEntrypointDerivedFromTheSessionPort(): void
    {
        $config = $this->buildWith([$this->runningSession('sess-1', 35042)]);

        $router = $this->router($config, 'run-sess-1');

        self::assertSame(['ap-35042'], $router['entryPoints']);
    }

    public function testRouterAcceptsAnySniBecauseThePortIdentifiesTheRun(): void
    {
        $config = $this->buildWith([$this->runningSession('sess-1', 35042)]);

        $router = $this->router($config, 'run-sess-1');

        self::assertSame('HostSNI(`*`)', $router['rule']);
    }

    public function testRouterRequestsARealCertificateForThePublicHost(): void
    {
        $config = $this->buildWith([$this->runningSession('sess-1', 35042)]);

        $tls = (array) $this->router($config, 'run-sess-1')['tls'];

        self::assertSame('letsencrypt', $tls['certResolver']);
        self::assertSame([['main' => 'runs.example.org']], $tls['domains']);
    }

    public function testServiceTargetsTheContainerInternalAddressNotThePublishedPort(): void
    {
        // Le port hôte de la session vaut 35042 ; le backend doit rester le port interne 38281,
        // sans quoi la story 37.3 ne pourrait pas refermer la publication sur l'hôte.
        $config = $this->buildWith([$this->runningSession('sess-1', 35042)]);

        $tcp = $config['tcp'];
        self::assertIsArray($tcp);
        $services = (array) $tcp['services'];
        $service = (array) $services['run-sess-1'];
        $loadBalancer = (array) $service['loadBalancer'];
        $servers = (array) $loadBalancer['servers'];
        $first = (array) $servers[0];

        self::assertSame('ap-server-sess-1:38281', $first['address']);
    }

    public function testEachSessionGetsItsOwnEntrypoint(): void
    {
        $config = $this->buildWith([
            $this->runningSession('sess-1', 35042),
            $this->runningSession('sess-2', 35043),
        ]);

        self::assertSame(['ap-35042'], $this->router($config, 'run-sess-1')['entryPoints']);
        self::assertSame(['ap-35043'], $this->router($config, 'run-sess-2')['entryPoints']);
    }

    private function runningSession(string $id, int $port): Session
    {
        return Session::createRunning(
            $id,
            'evt-1',
            'runner.example.org',
            $port,
            'secret',
            $port - 10000,
            new \DateTimeImmutable('2026-08-10 12:00:00'),
        );
    }

    /**
     * @param list<Session> $sessions
     *
     * @return array<string, mixed>
     */
    private function buildWith(array $sessions): array
    {
        $repository = self::createStub(SessionRepositoryInterface::class);
        $repository->method('findByStatus')->willReturn($sessions);

        return new TraefikConfigBuilder($repository, 'runs.example.org')->build();
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private function router(array $config, string $key): array
    {
        $tcp = $config['tcp'];
        self::assertIsArray($tcp);
        $routers = $tcp['routers'];
        self::assertIsArray($routers);
        self::assertArrayHasKey($key, $routers);
        $router = $routers[$key];
        self::assertIsArray($router);

        return $router;
    }
}
