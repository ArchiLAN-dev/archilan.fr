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
 * The player YAML is parsed here and re-dumped before it reaches the generator, and PHP has a
 * single empty value for both YAML shapes. Until this was fixed, every empty list in a player's
 * file arrived at Archipelago as an empty *mapping*.
 *
 * Starcraft 2 was the first world to die on it: `custom_mission_order` tells a layout from a plain
 * setting by `type(val) == dict`, so an `entry_rules: []` rewritten to `entry_rules: {}` was
 * promoted to a layout, merged with the defaults of the `global` block, and generation failed with
 * `Key 'entry_rules' error: ... should be instance of 'list'`.
 *
 * The reverse rewrite is just as fatal, hence the `start_inventory` case: `OptionDict::from_any`
 * refuses anything but a mapping, so an empty dict option must never come out as `[]`.
 */
final class RunnerGatewayEmptyCollectionsTest extends TestCase
{
    private const string HASH = 'ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34ab12cd34';

    private const string PLAYER_YAML = <<<'YAML'
        name: Starcraftinemao
        game: Starcraft 2
        Starcraft 2:
          start_inventory: {}
          local_items: []
          custom_mission_order:
            Default Campaign:
              Default Layout:
                size: 9
                type: grid
              display_name: 'null'
              entry_rules: []
              global:
                entry_rules: []
                missions: []
                mission_pool:
                  - all missions
        YAML;

    private string $capturedBody = '';

    public function testEmptyListsSurviveTheRoundTripToTheRunner(): void
    {
        $sent = $this->sentPlayerYaml();

        $parsed = Yaml::parse($sent);
        self::assertIsArray($parsed);
        $section = $parsed['Starcraft 2'] ?? [];
        self::assertIsArray($section);
        $missionOrder = $section['custom_mission_order'] ?? [];
        self::assertIsArray($missionOrder);
        $campaign = $missionOrder['Default Campaign'] ?? [];
        self::assertIsArray($campaign);
        $global = $campaign['global'] ?? [];
        self::assertIsArray($global);

        self::assertSame([], $campaign['entry_rules']);
        self::assertSame([], $global['entry_rules']);
        self::assertSame([], $global['missions']);
        self::assertSame(['all missions'], $global['mission_pool']);
        self::assertSame([], $section['local_items']);
    }

    public function testAnEmptyDictOptionIsStillWrittenAsAMapping(): void
    {
        $sent = $this->sentPlayerYaml();

        // Only the raw text can tell the two apart: both parse back to the same PHP array.
        self::assertMatchesRegularExpression('/start_inventory: \{\s*\}/', $sent);
        self::assertStringNotContainsString('start_inventory: []', $sent);
    }

    public function testTheSlotNameOverridesTheNameInThePlayerFile(): void
    {
        $parsed = Yaml::parse($this->sentPlayerYaml());

        self::assertIsArray($parsed);
        self::assertSame('masterkafey_S2', $parsed['name']);
    }

    /** The `playerYaml` string the gateway actually posts to the orchestrateur. */
    private function sentPlayerYaml(): string
    {
        $this->gateway()->configureSession('session-1', [[
            'apworldHash' => self::HASH,
            'slotName' => 'masterkafey_S2',
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

        return $yaml;
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
