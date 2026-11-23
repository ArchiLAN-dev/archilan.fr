<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Infrastructure\Http\RunnerGateway;
use Archilan\OrchestratorClient\OrchestratorClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Yaml\Yaml;

/**
 * Archipelago reads the player YAML as YAML 1.1 (PyYAML): `352e9` is a string there, a float for Symfony. The
 * re-dump sent `levelid: 352000000000.0` for a Beat Saber song (a hexadecimal BeatSaver key) and the generation
 * failed on "should be instance of 'str'". Such a value must reach the generator as the string the player wrote.
 */
final class RunnerGatewayExponentScalarsTest extends TestCase
{
    private const string HASH = 'ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34';

    private const string PLAYER_YAML = <<<'YAML'
        name: masterkafey_BS
        game: Beat Saber
        Beat Saber:
          songs:
            'Touch Tone Telephone':
              levelid: 352e9
              difficulty: Expert
            Other:
              levelid: 1E5   # a comment stays a comment
          keys:
            - 3e10
            - 1a2b3
          ratio: 1.5e+3
          speed: 1.25
          count: 12
        YAML;

    private string $capturedBody = '';

    public function testAnExponentLookingKeyReachesTheGeneratorAsTheStringThePlayerWrote(): void
    {
        $section = $this->sentSection();
        $songs = $section['songs'] ?? null;
        self::assertIsArray($songs);
        $touchTone = $songs['Touch Tone Telephone'] ?? null;
        self::assertIsArray($touchTone);
        $other = $songs['Other'] ?? null;
        self::assertIsArray($other);

        self::assertSame('352e9', $touchTone['levelid']);
        self::assertSame('Expert', $touchTone['difficulty']);
        self::assertSame('1E5', $other['levelid']);
        self::assertSame(['3e10', '1a2b3'], $section['keys']);
    }

    public function testRealNumbersStayNumbers(): void
    {
        $section = $this->sentSection();

        // `1.5e+3` is a float for PyYAML too (a dot and a signed exponent): left as it is.
        self::assertSame(1500.0, $section['ratio']);
        self::assertSame(1.25, $section['speed']);
        self::assertSame(12, $section['count']);
    }

    /**
     * @return array<mixed>
     */
    private function sentSection(): array
    {
        $this->gateway()->configureSession('session-1', [[
            'apworldHash' => self::HASH,
            'slotName' => 'masterkafey_BS',
            'playerYaml' => self::PLAYER_YAML,
        ]]);

        $decoded = json_decode($this->capturedBody, true);
        self::assertIsArray($decoded);
        $slots = $decoded['slots'] ?? [];
        self::assertIsArray($slots);
        $slot = $slots[0] ?? [];
        self::assertIsArray($slot);
        $yaml = $slot['playerYaml'] ?? null;
        self::assertIsString($yaml);

        $parsed = Yaml::parse($yaml);
        self::assertIsArray($parsed);
        $section = $parsed['Beat Saber'] ?? null;
        self::assertIsArray($section);

        return $section;
    }

    private function gateway(): RunnerGateway
    {
        /** @param array<string, mixed> $options */
        $handler = function (string $method, string $url, array $options): MockResponse {
            $body = $options['body'] ?? null;
            $this->capturedBody = is_string($body) ? $body : '';

            return new MockResponse(
                '{"valid":true,"slots":[]}',
                ['response_headers' => ['content-type' => 'application/json']],
            );
        };

        return new RunnerGateway(
            new OrchestratorClient('http://runner', 'key', new MockHttpClient($handler)),
            new NullLogger(),
        );
    }
}
