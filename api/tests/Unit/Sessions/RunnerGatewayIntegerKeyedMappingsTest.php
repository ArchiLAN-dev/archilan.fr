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
 * A weighted option keyed 0, 1, 2... is a mapping for Archipelago (value => weight). Collapsed to a PHP list, the
 * re-dump wrote it as a sequence: `vog_area_hints: {0: 50}` reached the generator as `- 50`, a list it drew 50
 * from, and Silksong's generation failed on "50 is higher than maximum 30" (#1226).
 */
final class RunnerGatewayIntegerKeyedMappingsTest extends TestCase
{
    private const string HASH = 'ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34';

    private const string PLAYER_YAML = <<<'YAML'
        name: firemann_KS
        game: 'Hollow Knight: Silksong'
        'Hollow Knight: Silksong':
          vog_area_hints:
            0: 50
          bench_count:
            0: 10
            1: 20
          mask_shards:
            2: 30
            7: 10
          starting_items:
            - Needle
            - Silk
        YAML;

    private string $capturedBody = '';

    public function testAWeightedOptionKeyedZeroStaysAMapping(): void
    {
        $section = $this->sentSection();

        self::assertInstanceOf(\stdClass::class, $section->vog_area_hints);
        self::assertSame([0 => 50], get_object_vars($section->vog_area_hints));
        self::assertInstanceOf(\stdClass::class, $section->bench_count);
        self::assertSame([0 => 10, 1 => 20], get_object_vars($section->bench_count));
    }

    public function testOtherMappingsAndSequencesKeepTheirShape(): void
    {
        $section = $this->sentSection();

        self::assertInstanceOf(\stdClass::class, $section->mask_shards);
        self::assertSame([2 => 30, 7 => 10], get_object_vars($section->mask_shards));
        self::assertSame(['Needle', 'Silk'], $section->starting_items);
    }

    private function sentSection(): \stdClass
    {
        $this->gateway()->configureSession('session-1', [[
            'apworldHash' => self::HASH,
            'slotName' => 'firemann_KS',
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

        $parsed = Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP);
        self::assertInstanceOf(\stdClass::class, $parsed);
        $section = $parsed->{'Hollow Knight: Silksong'} ?? null;
        self::assertInstanceOf(\stdClass::class, $section);

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
