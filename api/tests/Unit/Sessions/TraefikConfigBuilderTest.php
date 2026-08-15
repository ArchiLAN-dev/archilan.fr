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

        self::assertSame('https', $tls['certResolver']);
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

    public function testEachSessionAlsoGetsAPlaintextRouterOnTheSameEntrypoint(): void
    {
        // Story 37.8 : les clients qui ne parlent pas TLS - des mods de jeu, notamment - doivent
        // joindre la run sur le même port que les autres.
        $config = $this->buildWith([$this->runningSession('sess-1', 35042)]);

        $router = $this->router($config, 'plain-sess-1');

        self::assertSame(['ap-35042'], $router['entryPoints']);
        self::assertSame('HostSNI(`*`)', $router['rule']);
    }

    public function testThePlaintextRouterCarriesNoTlsConfiguration(): void
    {
        // C'est l'absence de cette clé, et rien d'autre, qui range le routeur dans le muxer
        // non-TLS de Traefik. Un `'tls' => []` le ferait basculer côté chiffré.
        $config = $this->buildWith([$this->runningSession('sess-1', 35042)]);

        self::assertArrayNotHasKey('tls', $this->router($config, 'plain-sess-1'));
    }

    public function testBothRoutersOfASessionShareTheSameAndOnlyService(): void
    {
        $config = $this->buildWith([$this->runningSession('sess-1', 35042)]);

        $tcp = $config['tcp'];
        self::assertIsArray($tcp);
        $services = (array) $tcp['services'];

        // Deux portes d'entrée, un seul backend : le routeur clair ne duplique pas le service.
        self::assertCount(1, $services);
        self::assertArrayHasKey('run-sess-1', $services);
        self::assertSame('run-sess-1', $this->router($config, 'run-sess-1')['service']);
        self::assertSame('run-sess-1', $this->router($config, 'plain-sess-1')['service']);
    }

    public function testRouterKeysNeverCollideBetweenSchemes(): void
    {
        // Le cas adverse que le choix de deux préfixes rend impossible : avec un suffixe, la clé
        // claire de `sess-1` vaudrait `run-sess-1-plain`, soit la clé chiffrée d'une session
        // nommée `sess-1-plain`, et un run en écraserait silencieusement un autre.
        $config = $this->buildWith([
            $this->runningSession('sess-1', 35042),
            $this->runningSession('plain-sess-1', 35043),
        ]);

        $keys = $this->routerKeys($config);

        self::assertCount(4, $keys);
        self::assertCount(4, array_unique($keys));
        self::assertContains('run-sess-1', $keys);
        self::assertContains('plain-sess-1', $keys);
        self::assertContains('run-plain-sess-1', $keys);
        self::assertContains('plain-plain-sess-1', $keys);
    }

    public function testASessionProducesExactlyTwoRoutersAndNothingMore(): void
    {
        $config = $this->buildWith([$this->runningSession('sess-1', 35042)]);

        self::assertSame(['run-sess-1', 'plain-sess-1'], $this->routerKeys($config));
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

        return new TraefikConfigBuilder($repository, 'runs.example.org', 'https')->build();
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<array-key>
     */
    private function routerKeys(array $config): array
    {
        $tcp = $config['tcp'];
        self::assertIsArray($tcp);
        $routers = $tcp['routers'];
        self::assertIsArray($routers);

        return array_keys($routers);
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
