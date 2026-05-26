<?php

declare(strict_types=1);

namespace App\Tests\Unit\WeeklyRuns;

use App\WeeklyRuns\Infrastructure\HttpWeeklyRunnerGateway;
use Archilan\OrchestratorClient\OrchestratorClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpWeeklyRunnerGatewayTest extends TestCase
{
    private function makeGateway(MockHttpClient $client, string $publicHost = 'runner.example.com'): HttpWeeklyRunnerGateway
    {
        $orchestrateur = new OrchestratorClient(
            baseUrl: 'http://orchestrateur.test',
            apiKey: 'test-key',
            httpClient: new MockHttpClient(),
        );

        return new HttpWeeklyRunnerGateway(
            client: $orchestrateur,
            httpClient: $client,
            logger: new NullLogger(),
            runnerBaseUrl: 'http://runner.internal',
            runnerApiKey: 'test-key',
            runnerPublicHost: $publicHost,
            symfonyInternalUrl: 'http://api.test',
            mercureHubUrl: 'http://mercure.test/.well-known/mercure',
            centralApiSecret: 'test-secret',
            bridgeInternalToken: '',
        );
    }

    private function successResponse(int $port = 38281, string $password = 'secret'): MockResponse
    {
        return new MockResponse((string) json_encode([
            'sessionId' => 'entry-1',
            'containerHost' => '0.0.0.0',
            'containerPort' => $port,
            'serverPassword' => $password,
        ], \JSON_THROW_ON_ERROR));
    }

    public function testLaunchFromSeedUsesRunnerPublicHostNotContainerHost(): void
    {
        $gateway = $this->makeGateway(new MockHttpClient($this->successResponse()), 'my-public-runner.archilan.fr');

        $result = $gateway->launchFromSeed('entry-1', '/workspace/run-1/output/world.archipelago');

        self::assertSame('my-public-runner.archilan.fr', $result['connectionInfo']['host']);
        self::assertNotSame('0.0.0.0', $result['connectionInfo']['host']);
    }

    public function testLaunchFromSeedMapsContainerPortToPort(): void
    {
        $gateway = $this->makeGateway(new MockHttpClient($this->successResponse(port: 38282)));

        $result = $gateway->launchFromSeed('entry-1', '/workspace/run-1/output/world.archipelago');

        self::assertSame(38282, $result['connectionInfo']['port']);
    }

    public function testLaunchFromSeedSendsOutputFileInBody(): void
    {
        /** @var string|null $capturedOutputFile */
        $capturedOutputFile = null;
        $factory = function (string $method, string $requestUrl, array $options) use (&$capturedOutputFile): MockResponse {
            $raw = $options['body'] ?? null;
            if (is_string($raw)) {
                $payload = json_decode($raw, true);
                if (is_array($payload)) {
                    $of = $payload['outputFile'] ?? null;
                    $capturedOutputFile = is_string($of) ? $of : null;
                }
            }

            return new MockResponse((string) json_encode([
                'sessionId' => 'entry-2',
                'containerHost' => '0.0.0.0',
                'containerPort' => 38282,
                'serverPassword' => null,
            ], \JSON_THROW_ON_ERROR));
        };

        $gateway = $this->makeGateway(new MockHttpClient($factory));
        $gateway->launchFromSeed('entry-2', '/workspace/run-1/output/my-world.archipelago');

        self::assertSame('/workspace/run-1/output/my-world.archipelago', $capturedOutputFile);
    }

    public function testLaunchFromSeedThrowsRuntimeExceptionOnErrorResponse(): void
    {
        $errorBody = (string) json_encode(['error' => 'not_ready', 'details' => 'session not found'], \JSON_THROW_ON_ERROR);
        $gateway = $this->makeGateway(new MockHttpClient(new MockResponse($errorBody)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not_ready/');

        $gateway->launchFromSeed('entry-3', '/workspace/run-1/output/world.archipelago');
    }

    public function testLaunchFromSeedSetsExternalSessionId(): void
    {
        $gateway = $this->makeGateway(new MockHttpClient($this->successResponse()));

        $result = $gateway->launchFromSeed('my-entry-id', '/workspace/run-1/output/world.archipelago');

        self::assertSame('my-entry-id', $result['externalSessionId']);
    }

    public function testLaunchFromSeedHandlesNullServerPassword(): void
    {
        $body = (string) json_encode([
            'sessionId' => 'entry-5',
            'containerHost' => '0.0.0.0',
            'containerPort' => 38281,
            'serverPassword' => null,
        ], \JSON_THROW_ON_ERROR);
        $gateway = $this->makeGateway(new MockHttpClient(new MockResponse($body)));

        $result = $gateway->launchFromSeed('entry-5', '/workspace/run-1/output/world.archipelago');

        self::assertNull($result['connectionInfo']['password']);
    }
}
