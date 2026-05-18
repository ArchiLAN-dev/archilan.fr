<?php

declare(strict_types=1);

namespace App\Tests\Unit\WeeklyRuns;

use App\WeeklyRuns\Infrastructure\HttpWeeklyRunnerGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpWeeklyRunnerGatewayTest extends TestCase
{
    private function makeGateway(MockHttpClient $client, string $publicHost = 'runner.example.com'): HttpWeeklyRunnerGateway
    {
        return new HttpWeeklyRunnerGateway(
            httpClient: $client,
            logger: new NullLogger(),
            runnerBaseUrl: 'http://runner.internal',
            runnerApiKey: 'test-key',
            runnerPublicHost: $publicHost,
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

    public function testLaunchEntryUsesRunnerPublicHostNotContainerHost(): void
    {
        $gateway = $this->makeGateway(new MockHttpClient($this->successResponse()), 'my-public-runner.archilan.fr');

        $result = $gateway->launchEntry('entry-1', 'seed123', 'abc.apworld', 'http://minio/abc.apworld', 'Alice', "name: Alice\n");

        self::assertSame('my-public-runner.archilan.fr', $result['connectionInfo']['host']);
        self::assertNotSame('0.0.0.0', $result['connectionInfo']['host']);
    }

    public function testLaunchEntryMapsContainerPortToPort(): void
    {
        $gateway = $this->makeGateway(new MockHttpClient($this->successResponse(port: 38282)));

        $result = $gateway->launchEntry('entry-1', 'seed', 'abc.apworld', 'http://minio/url', 'Alice', 'yaml');

        self::assertSame(38282, $result['connectionInfo']['port']);
    }

    public function testLaunchEntrySendsApworldDownloadUrlInBody(): void
    {
        // Symfony's HttpClient converts the `json:` option to a `body:` string before calling the factory.
        /** @var string|null $capturedDownloadUrl */
        $capturedDownloadUrl = null;
        $factory = function (string $method, string $requestUrl, array $options) use (&$capturedDownloadUrl): MockResponse {
            $raw = $options['body'] ?? null;
            if (is_string($raw)) {
                $payload = json_decode($raw, true);
                if (is_array($payload)) {
                    $slots = $payload['slots'] ?? null;
                    if (is_array($slots)) {
                        $slot = $slots[0] ?? null;
                        if (is_array($slot)) {
                            $dlUrl = $slot['apworldDownloadUrl'] ?? null;
                            $capturedDownloadUrl = is_string($dlUrl) ? $dlUrl : null;
                        }
                    }
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
        $gateway->launchEntry('entry-2', 'seed', 'key.apworld', 'http://minio/presigned-url', 'Bob', "name: Bob\n");

        self::assertSame('http://minio/presigned-url', $capturedDownloadUrl);
    }

    public function testLaunchEntryThrowsRuntimeExceptionOnErrorResponse(): void
    {
        $errorBody = (string) json_encode(['error' => 'generation_failed', 'details' => 'docker failed'], \JSON_THROW_ON_ERROR);
        $gateway = $this->makeGateway(new MockHttpClient(new MockResponse($errorBody)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/generation_failed/');

        $gateway->launchEntry('entry-3', 'seed', 'key.apworld', 'http://minio/url', 'Carol', 'yaml');
    }

    public function testLaunchEntrySetsExternalSessionId(): void
    {
        $gateway = $this->makeGateway(new MockHttpClient($this->successResponse()));

        $result = $gateway->launchEntry('my-entry-id', 'seed', 'key', 'http://minio/url', 'Dan', 'yaml');

        self::assertSame('my-entry-id', $result['externalSessionId']);
    }

    public function testLaunchEntryHandlesNullServerPassword(): void
    {
        $body = (string) json_encode([
            'sessionId' => 'entry-5',
            'containerHost' => '0.0.0.0',
            'containerPort' => 38281,
            'serverPassword' => null,
        ], \JSON_THROW_ON_ERROR);
        $gateway = $this->makeGateway(new MockHttpClient(new MockResponse($body)));

        $result = $gateway->launchEntry('entry-5', 'seed', 'key', 'http://minio/url', 'Eve', 'yaml');

        self::assertNull($result['connectionInfo']['password']);
    }
}
