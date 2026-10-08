<?php

declare(strict_types=1);

namespace App\Tests\Unit\WeeklyRuns;

use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use App\WeeklyRuns\Infrastructure\Adapter\OrchestratorWeeklyRunnerGateway;
use Archilan\OrchestratorClient\OrchestratorClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 23.15: a launch the API gave up on (the orchestrateur took longer than the API waited) left the
 * entry's session running on the orchestrateur, and every later click hit its 409. The gateway now adopts
 * that session instead of launching again.
 */
final class OrchestratorWeeklyRunnerGatewayTest extends TestCase
{
    private const string ENTRY = 'adc4e7a63e96f913';

    /** @var list<string> "METHOD path" of every request the gateway sent */
    private array $requests = [];

    public function testARunningSessionIsAdoptedWithoutLaunchingAgain(): void
    {
        $gateway = $this->gateway([$this->session('running', 38281)]);

        $result = $gateway->launchEntry(self::ENTRY, 'hash', 'yaml', 'runs/r1/output.zip');

        self::assertSame(self::ENTRY, $result['externalSessionId']);
        self::assertSame(38281, $result['connectionInfo']['port']);
        self::assertSame('secret', $result['connectionInfo']['password']);
        self::assertSame(25015, $result['bridgePort']);
        self::assertSame(['GET /sessions/'.self::ENTRY], $this->requests, 'no configure, no launch');
    }

    public function testALaunchStillUnderWayIsAwaitedThenAdopted(): void
    {
        $gateway = $this->gateway([$this->session('launching', null), $this->session('running', 38281)]);

        $result = $gateway->launchEntry(self::ENTRY, 'hash', 'yaml', 'runs/r1/output.zip');

        self::assertSame(38281, $result['connectionInfo']['port']);
        self::assertSame(['GET /sessions/'.self::ENTRY, 'GET /sessions/'.self::ENTRY], $this->requests);
    }

    public function testWithoutASessionTheEntryIsLaunched(): void
    {
        $gateway = $this->gateway([
            new MockResponse('{"error":"session not found"}', ['http_code' => 404]),
            new MockResponse('{"valid":true}', ['http_code' => 200]),
            new MockResponse('', ['http_code' => 202]),
            $this->session('running', 38281),
        ]);

        $result = $gateway->launchEntry(self::ENTRY, 'hash', 'yaml', 'runs/r1/output.zip');

        self::assertSame(38281, $result['connectionInfo']['port']);
        self::assertSame([
            'GET /sessions/'.self::ENTRY,
            'POST /sessions/'.self::ENTRY.'/configure',
            'POST /sessions/'.self::ENTRY.'/launch-from-file',
            'GET /sessions/'.self::ENTRY,
        ], $this->requests);
    }

    public function testAStoppedSessionIsLaunchedAgainRatherThanAdopted(): void
    {
        $gateway = $this->gateway([
            $this->session('stopped', null),
            new MockResponse('{"valid":true}', ['http_code' => 200]),
            new MockResponse('', ['http_code' => 202]),
            $this->session('running', 38281),
        ]);

        $gateway->launchEntry(self::ENTRY, 'hash', 'yaml', 'runs/r1/output.zip');

        self::assertContains('POST /sessions/'.self::ENTRY.'/launch-from-file', $this->requests);
    }

    /** @param list<MockResponse> $responses served in order, one per request */
    private function gateway(array $responses): OrchestratorWeeklyRunnerGateway
    {
        $http = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requests[] = $method.' '.(string) parse_url($url, \PHP_URL_PATH);
            $response = array_shift($responses);
            self::assertInstanceOf(MockResponse::class, $response, 'unexpected request: '.$method.' '.$url);

            return $response;
        });

        $storage = self::createStub(MinioStorageInterface::class);
        $storage->method('download')->willReturn('zip-bytes');

        return new OrchestratorWeeklyRunnerGateway(
            new OrchestratorClient('http://orchestrateur', 'key', $http),
            $storage,
            $http,
            new NullLogger(),
            'http://orchestrateur',
            'key',
            'play.archilan.fr',
            'sessions',
            0,
        );
    }

    private function session(string $status, ?int $apPort): MockResponse
    {
        return new MockResponse((string) json_encode([
            'sessionId' => self::ENTRY,
            'status' => $status,
            'apPort' => $apPort,
            'bridgePort' => 25015,
            'serverPassword' => 'secret',
            'createdAt' => '2026-10-08T17:13:46Z',
            'updatedAt' => '2026-10-08T17:14:50Z',
        ]), ['http_code' => 200]);
    }
}
