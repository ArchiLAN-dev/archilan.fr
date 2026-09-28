<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Infrastructure\Http\RunnerGateway;
use Archilan\OrchestratorClient\OrchestratorClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 38.8: a verdict says which Archipelago image produced it, and the API knows which one runs.
 */
final class RunnerGatewayImageTest extends TestCase
{
    public function testThePreflightPayloadCarriesTheImage(): void
    {
        $gateway = $this->gateway(new MockResponse((string) json_encode(['apworlds' => [
            ['hash' => 'h1', 'game' => 'Crystal Project', 'preflight' => [
                'status' => 'passed', 'checkedAt' => '2026-09-26T04:00:00Z', 'image' => 'ghcr.io/archilan-dev/archipelago:0.16.1', 'imageId' => 'sha256:abc123',
            ]],
            ['hash' => 'h0', 'game' => 'Old', 'preflight' => ['status' => 'passed', 'checkedAt' => '2026-07-01T10:00:00Z']],
        ]])));

        $verdicts = $gateway->fetchApworldPreflights();

        self::assertSame('ghcr.io/archilan-dev/archipelago:0.16.1', $verdicts['h1']['image'] ?? null);
        self::assertSame('sha256:abc123', $verdicts['h1']['imageId'] ?? null);
        self::assertNull($verdicts['h0']['image'] ?? null, 'a verdict older than the story has no image');
        self::assertNull($verdicts['h0']['imageId'] ?? null);
    }

    public function testThePreflightPayloadCarriesTheWarningOfAPass(): void
    {
        // Story 38.12: a pass the generator warned about (accessibility not met, as the Launcher allows).
        $gateway = $this->gateway(new MockResponse((string) json_encode(['apworlds' => [
            ['hash' => 'h1', 'game' => 'Dragon Ball Z Budokai Tenkaichi 2', 'preflight' => [
                'status' => 'passed', 'checkedAt' => '2026-09-28T04:00:00Z', 'warning' => 'Missing: [Discover: Evil Dragon]',
            ]],
            ['hash' => 'h0', 'game' => 'Clean', 'preflight' => ['status' => 'passed', 'checkedAt' => '2026-09-28T04:00:00Z']],
        ]])));

        $verdicts = $gateway->fetchApworldPreflights();

        self::assertSame('Missing: [Discover: Evil Dragon]', $verdicts['h1']['warning'] ?? null);
        self::assertSame('', $verdicts['h0']['warning'] ?? null, 'a clean pass has no warning');
        self::assertFalse($verdicts['h1']['blocks'], 'a pass with a warning never blocks');
    }

    public function testFetchRuntimeReturnsTheImageInUse(): void
    {
        $gateway = $this->gateway(new MockResponse((string) json_encode(['apImage' => 'ghcr.io/archilan-dev/archipelago:0.16.1', 'apImageId' => 'sha256:abc123'])));

        self::assertSame(['apImage' => 'ghcr.io/archilan-dev/archipelago:0.16.1', 'apImageId' => 'sha256:abc123'], $gateway->fetchRuntime());
    }

    public function testFetchRuntimeReturnsNullWhenTheRunnerIsDown(): void
    {
        $gateway = $this->gateway(new MockResponse('', ['error' => 'connection refused']));

        self::assertNull($gateway->fetchRuntime());
    }

    private function gateway(MockResponse $response): RunnerGateway
    {
        return new RunnerGateway(new OrchestratorClient('http://runner', 'key', new MockHttpClient([$response])), new NullLogger());
    }
}
