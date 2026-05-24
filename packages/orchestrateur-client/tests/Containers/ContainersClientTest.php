<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Tests\Containers;

use Archilan\OrchestratorClient\Containers\ContainersClient;
use Archilan\OrchestratorClient\Containers\Response\ContainerResponse;
use Archilan\OrchestratorClient\Containers\Response\CreateContainerResult;
use Archilan\OrchestratorClient\Http\HttpTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ContainersClientTest extends TestCase
{
    private function client(MockResponse $response): ContainersClient
    {
        $transport = new HttpTransport(new MockHttpClient($response), 'http://localhost:8000', 'key');

        return new ContainersClient($transport);
    }

    private function containerJson(string $sessionId = 'abc', string $status = 'running'): string
    {
        return json_encode([
            'sessionId' => $sessionId,
            'port' => 25000,
            'status' => $status,
            'containerId' => 'container-id-123',
            'image' => 'archilan-bridge:latest',
            'createdAt' => '2026-01-01T00:00:00Z',
            'updatedAt' => '2026-01-01T00:00:00Z',
        ]) ?: '';
    }

    public function testCreate_returnsCreateContainerResult(): void
    {
        $body = json_encode(['sessionId' => 'abc', 'port' => 25000, 'status' => 'starting']) ?: '';
        $client = $this->client(new MockResponse($body, ['http_code' => 202]));
        $result = $client->create('abc', 'adminpass');

        $this->assertInstanceOf(CreateContainerResult::class, $result);
        $this->assertSame('abc', $result->sessionId);
        $this->assertSame(25000, $result->port);
        $this->assertSame('starting', $result->status);
    }

    public function testList_returnsContainerArray(): void
    {
        $body = json_encode(['containers' => [
            json_decode($this->containerJson('abc'), true),
            json_decode($this->containerJson('xyz', 'stopped'), true),
        ]]) ?: '';
        $client = $this->client(new MockResponse($body, ['http_code' => 200]));
        $list = $client->list();

        $this->assertCount(2, $list);
        $this->assertInstanceOf(ContainerResponse::class, $list[0]);
        $this->assertSame('abc', $list[0]->sessionId);
        $this->assertSame('xyz', $list[1]->sessionId);
    }

    public function testGet_returnsContainerResponse(): void
    {
        $client = $this->client(new MockResponse($this->containerJson(), ['http_code' => 200]));
        $container = $client->get('abc');

        $this->assertInstanceOf(ContainerResponse::class, $container);
        $this->assertSame('abc', $container->sessionId);
        $this->assertSame(25000, $container->port);
        $this->assertSame('running', $container->status);
    }

    public function testStop_void(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 204]));
        $client->stop('abc');
        $this->expectNotToPerformAssertions();
    }

    public function testReload_void(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 204]));
        $client->reload('abc');
        $this->expectNotToPerformAssertions();
    }

    public function testRemove_void(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 204]));
        $client->remove('abc');
        $this->expectNotToPerformAssertions();
    }
}
