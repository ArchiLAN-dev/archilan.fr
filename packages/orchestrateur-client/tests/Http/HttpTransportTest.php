<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Tests\Http;

use Archilan\OrchestratorClient\Exception\ConflictException;
use Archilan\OrchestratorClient\Exception\OrchestratorException;
use Archilan\OrchestratorClient\Exception\ServiceUnavailableException;
use Archilan\OrchestratorClient\Exception\SessionNotFoundException;
use Archilan\OrchestratorClient\Exception\TransportException;
use Archilan\OrchestratorClient\Http\HttpTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpTransportTest extends TestCase
{
    private function transport(MockResponse $response): HttpTransport
    {
        return new HttpTransport(new MockHttpClient($response), 'http://localhost:8000', 'test-key');
    }

    public function testPostVoid_succeedsOn202(): void
    {
        $transport = $this->transport(new MockResponse('', ['http_code' => 202]));
        $transport->postVoid('/sessions/abc/generate', ['adminPassword' => 'x']);
        $this->expectNotToPerformAssertions();
    }

    public function testPostJson_returnsDecodedArray(): void
    {
        $transport = $this->transport(new MockResponse('{"valid":true,"slots":[]}', ['http_code' => 200]));
        $data = $transport->postJson('/sessions/abc/preflight', []);
        $this->assertSame(true, $data['valid']);
    }

    public function testGetJson_returnsDecodedArray(): void
    {
        $transport = $this->transport(new MockResponse('{"sessionId":"abc","status":"running","createdAt":"2026-01-01T00:00:00Z","updatedAt":"2026-01-01T00:00:00Z"}', ['http_code' => 200]));
        $data = $transport->getJson('/sessions/abc');
        $this->assertSame('abc', $data['sessionId']);
    }

    public function testGetRaw_returnsRawString(): void
    {
        $transport = $this->transport(new MockResponse("name: ArchiLAN\n", ['http_code' => 200]));
        $yaml = $transport->getRaw('/apworlds/deadbeef/yaml');
        $this->assertSame("name: ArchiLAN\n", $yaml);
    }

    public function testDeleteVoid_succeedsOn204(): void
    {
        $transport = $this->transport(new MockResponse('', ['http_code' => 204]));
        $transport->deleteVoid('/sessions/abc');
        $this->expectNotToPerformAssertions();
    }

    public function test404_throwsSessionNotFoundException(): void
    {
        $transport = $this->transport(new MockResponse('{"error":"session not found"}', ['http_code' => 404]));
        $this->expectException(SessionNotFoundException::class);
        $transport->getJson('/sessions/unknown');
    }

    public function test409_throwsConflictException_withErrorCode(): void
    {
        $transport = $this->transport(new MockResponse('{"error":"already_in_progress"}', ['http_code' => 409]));
        try {
            $transport->postVoid('/sessions/abc/generate', []);
            $this->fail('Expected ConflictException');
        } catch (ConflictException $e) {
            $this->assertSame('already_in_progress', $e->errorCode);
        }
    }

    public function test409_notReady_throwsConflictException(): void
    {
        $transport = $this->transport(new MockResponse('{"error":"not_ready"}', ['http_code' => 409]));
        try {
            $transport->postVoid('/sessions/abc/launch', []);
            $this->fail('Expected ConflictException');
        } catch (ConflictException $e) {
            $this->assertSame('not_ready', $e->errorCode);
        }
    }

    public function test503_throwsServiceUnavailableException(): void
    {
        $transport = $this->transport(new MockResponse('{"error":"storage not configured"}', ['http_code' => 503]));
        $this->expectException(ServiceUnavailableException::class);
        $transport->postVoid('/sessions/abc/generate', []);
    }

    public function test5xx_throwsOrchestratorException(): void
    {
        $transport = $this->transport(new MockResponse('{"error":"internal error"}', ['http_code' => 500]));
        $this->expectException(OrchestratorException::class);
        $transport->postVoid('/sessions/abc/generate', []);
    }

    public function testNetworkError_throwsTransportException(): void
    {
        $mock = new MockHttpClient(function (): never {
            throw new \RuntimeException('Connection refused');
        });
        $transport = new HttpTransport($mock, 'http://localhost:8000', 'test-key');
        $this->expectException(TransportException::class);
        $transport->postVoid('/sessions/abc/generate', []);
    }

    public function testAuthHeaderIsAlwaysInjected(): void
    {
        $capturedHeaders = [];
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders): MockResponse {
            $capturedHeaders = $options['headers'] ?? [];

            return new MockResponse('', ['http_code' => 204]);
        });
        $transport = new HttpTransport($mock, 'http://localhost:8000', 'my-secret');
        $transport->deleteVoid('/sessions/abc');

        $this->assertContains('Authorization: Bearer my-secret', $capturedHeaders);
    }
}
