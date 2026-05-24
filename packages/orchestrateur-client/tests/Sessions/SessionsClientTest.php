<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Tests\Sessions;

use Archilan\OrchestratorClient\Http\HttpTransport;
use Archilan\OrchestratorClient\Sessions\Request\ConfigureRequest;
use Archilan\OrchestratorClient\Sessions\Request\ConfigureSlot;
use Archilan\OrchestratorClient\Sessions\Request\PreflightRequest;
use Archilan\OrchestratorClient\Sessions\Request\PreflightSlot;
use Archilan\OrchestratorClient\Sessions\Request\SlotOptions;
use Archilan\OrchestratorClient\Sessions\Yaml\Option\ChoiceOption;
use Archilan\OrchestratorClient\Sessions\Yaml\Option\RangeOption;
use Archilan\OrchestratorClient\Sessions\Yaml\Option\ToggleOption;
use Archilan\OrchestratorClient\Sessions\Yaml\Option\Weighted;
use Archilan\OrchestratorClient\Sessions\Response\ConfigureResult;
use Archilan\OrchestratorClient\Sessions\Response\PreflightResult;
use Archilan\OrchestratorClient\Sessions\Response\SessionResponse;
use Archilan\OrchestratorClient\Sessions\SessionsClient;
use Archilan\OrchestratorClient\Sessions\Yaml\PlayerYaml;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SessionsClientTest extends TestCase
{
    private function client(MockResponse $response): SessionsClient
    {
        $transport = new HttpTransport(new MockHttpClient($response), 'http://localhost:8000', 'key');

        return new SessionsClient($transport);
    }

    private function sessionJson(string $sessionId = 'abc', string $status = 'running'): string
    {
        return json_encode([
            'sessionId' => $sessionId,
            'status' => $status,
            'bridgePort' => 25000,
            'apPort' => 35000,
            'serverPassword' => null,
            'outputFile' => null,
            'createdAt' => '2026-01-01T00:00:00Z',
            'updatedAt' => '2026-01-01T00:00:00Z',
        ]) ?: '';
    }

    public function testGenerate_void(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 202]));
        $client->generate('abc', 'adminpass');
        $this->expectNotToPerformAssertions();
    }

    public function testGenerate_withSeed_void(): void
    {
        $called = false;
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$called): MockResponse {
            $called = true;
            $body = json_decode($options['body'] ?? '{}', true);
            $this->assertIsArray($body);
            $this->assertSame('myseed', $body['seed'] ?? null);

            return new MockResponse('', ['http_code' => 202]);
        });
        $client = new SessionsClient(new HttpTransport($mock, 'http://localhost:8000', 'key'));
        $client->generate('abc', 'adminpass', 'myseed');
        $this->assertTrue($called);
    }

    public function testLaunch_void(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 202]));
        $client->launch('abc', 'adminpass');
        $this->expectNotToPerformAssertions();
    }

    public function testStop_void(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 204]));
        $client->stop('abc');
        $this->expectNotToPerformAssertions();
    }

    public function testRestart_void(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 202]));
        $client->restart('abc');
        $this->expectNotToPerformAssertions();
    }

    public function testDelete_void(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 204]));
        $client->delete('abc');
        $this->expectNotToPerformAssertions();
    }

    public function testGet_returnsSessionResponse(): void
    {
        $client = $this->client(new MockResponse($this->sessionJson('abc', 'running'), ['http_code' => 200]));
        $session = $client->get('abc');

        $this->assertInstanceOf(SessionResponse::class, $session);
        $this->assertSame('abc', $session->sessionId);
        $this->assertSame('running', $session->status);
        $this->assertSame(25000, $session->bridgePort);
        $this->assertSame(35000, $session->apPort);
    }

    public function testPreflight_returnsPreflightResult(): void
    {
        $body = json_encode([
            'valid' => false,
            'slots' => [
                ['slotId' => 's1', 'proposedName' => 'Player_Zelda', 'errors' => ['Option X required']],
            ],
        ]) ?: '';
        $client = $this->client(new MockResponse($body, ['http_code' => 200]));

        $request = new PreflightRequest([new PreflightSlot(slotId: 's1', playerName: 'Player')]);
        $result = $client->preflight('abc', $request);

        $this->assertInstanceOf(PreflightResult::class, $result);
        $this->assertFalse($result->valid);
        $this->assertCount(1, $result->slots);
        $this->assertSame('Player_Zelda', $result->slots[0]->proposedName);
        $this->assertSame(['Option X required'], $result->slots[0]->errors);
    }

    public function testConfigure_returnsConfigureResult(): void
    {
        $body = json_encode([
            'valid' => false,
            'slots' => [
                ['playerName' => 'Peach', 'errors' => ['apworld introuvable']],
                ['playerName' => 'Link', 'errors' => []],
            ],
        ]) ?: '';
        $client = $this->client(new MockResponse($body, ['http_code' => 200]));

        $hash = str_repeat('a', 64);
        $request = new ConfigureRequest([
            ConfigureSlot::fromYaml($hash, new PlayerYaml('Peach', 'Super Mario World')),
            ConfigureSlot::fromYaml($hash, new PlayerYaml('Link', 'A Link to the Past')),
        ]);
        $result = $client->configure('abc', $request);

        $this->assertInstanceOf(ConfigureResult::class, $result);
        $this->assertFalse($result->valid);
        $this->assertCount(2, $result->slots);
        $this->assertSame('Peach', $result->slots[0]->playerName);
        $this->assertSame(['apworld introuvable'], $result->slots[0]->errors);
        $this->assertSame('Link', $result->slots[1]->playerName);
        $this->assertSame([], $result->slots[1]->errors);
    }

    public function testConfigure_valid_returnsConfigureResult(): void
    {
        $body = json_encode([
            'valid' => true,
            'slots' => [
                ['playerName' => 'Samus', 'errors' => []],
            ],
        ]) ?: '';
        $called = false;
        $validHash = str_repeat('b', 64);
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$called, $body, $validHash): MockResponse {
            $called = true;
            $this->assertSame('POST', $method);
            $this->assertStringContainsString('/sessions/s1/configure', $url);
            $decoded = json_decode($options['body'] ?? '{}', true);
            $this->assertIsArray($decoded);
            /** @var array{slots?: array<array<string, string>>} $decoded */
            $slots = $decoded['slots'] ?? [];
            $this->assertCount(1, $slots);
            $this->assertSame($validHash, $slots[0]['apworldHash'] ?? null);
            $yaml = $slots[0]['playerYaml'] ?? '';
            $this->assertStringContainsString('Samus', $yaml);

            return new MockResponse($body, ['http_code' => 200]);
        });
        $client = new SessionsClient(new HttpTransport($mock, 'http://localhost:8000', 'key'));

        $result = $client->configure('s1', new ConfigureRequest([
            ConfigureSlot::fromYaml($validHash, new PlayerYaml('Samus', 'Super Metroid')),
        ]));

        $this->assertTrue($called);
        $this->assertTrue($result->valid);
    }

    public function testConfigureSlot_invalidHash_throwsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConfigureSlot::fromYaml('tooshort', new PlayerYaml('Jean', 'Timespinner'));
    }

    public function testConfigureSlot_fromOptions_sendsOptionsPayload(): void
    {
        $hash = str_repeat('c', 64);
        $body = json_encode([
            'valid' => true,
            'slots' => [['playerName' => 'Jean', 'errors' => []]],
        ]) ?: '';
        $decoded = null;
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$decoded, $body): MockResponse {
            $decoded = json_decode($options['body'] ?? '{}', true);

            return new MockResponse($body, ['http_code' => 200]);
        });
        $client = new SessionsClient(new HttpTransport($mock, 'http://localhost:8000', 'key'));

        $client->configure('s1', new ConfigureRequest([
            ConfigureSlot::fromOptions($hash, new SlotOptions('Jean', [
                new ChoiceOption('goal', 'beat_the_king'),
                new RangeOption('trap_percentage', 25),
                new ToggleOption('death_link', false),
                new ChoiceOption('filler_weights', [new Weighted('Coins', 25), new Weighted('Bars', 75)]),
            ])),
        ]));

        $this->assertIsArray($decoded);
        /** @var array{slots: array<array<string, mixed>>} $decoded */
        $slot = $decoded['slots'][0];
        $this->assertSame($hash, $slot['apworldHash']);
        $this->assertArrayNotHasKey('playerYaml', $slot);
        $this->assertArrayHasKey('options', $slot);
        /** @var array{playerName: string, values: array<string, mixed>} $options */
        $options = $slot['options'];
        $this->assertSame('Jean', $options['playerName']);
        $this->assertSame('beat_the_king', $options['values']['goal']);
        $this->assertSame(25, $options['values']['trap_percentage']);
        $this->assertSame(0, $options['values']['death_link']);
        $this->assertSame(['Coins' => 25, 'Bars' => 75], $options['values']['filler_weights']);
    }

    public function testConfigureSlot_fromOptions_invalidHash_throwsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConfigureSlot::fromOptions('bad-hash', new SlotOptions('Jean'));
    }

    public function testLaunchFromFile_sendsMultipart(): void
    {
        $capturedBody = '';
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
            $this->assertSame('POST', $method);
            $this->assertStringContainsString('/sessions/abc/launch-from-file', $url);
            $capturedBody = $options['body'] ?? '';

            return new MockResponse('', ['http_code' => 202]);
        });
        $client = new SessionsClient(new HttpTransport($mock, 'http://localhost:8000', 'key'));
        $client->launchFromFile('abc', 'binary-content', 'game.archipelago', 'adminpass');

        $this->assertIsString($capturedBody);
        $this->assertStringContainsString('adminpass', $capturedBody);
    }
}
