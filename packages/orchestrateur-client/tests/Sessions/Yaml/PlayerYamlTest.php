<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Tests\Sessions\Yaml;

use Archilan\OrchestratorClient\Sessions\Yaml\Option\ChoiceOption;
use Archilan\OrchestratorClient\Sessions\Yaml\Option\ItemDictOption;
use Archilan\OrchestratorClient\Sessions\Yaml\Option\ItemListOption;
use Archilan\OrchestratorClient\Sessions\Yaml\Option\RangeOption;
use Archilan\OrchestratorClient\Sessions\Yaml\Option\ToggleOption;
use Archilan\OrchestratorClient\Sessions\Yaml\Option\Weighted;
use Archilan\OrchestratorClient\Sessions\Yaml\PlayerYaml;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PlayerYamlTest extends TestCase
{
    public function testToArray_minimal(): void
    {
        $yaml = new PlayerYaml(name: 'Jean', game: 'Timespinner');

        $this->assertSame([
            'name' => 'Jean',
            'game' => 'Timespinner',
        ], $yaml->toArray());
    }

    public function testToArray_withDescription(): void
    {
        $yaml = new PlayerYaml(name: 'Jean', game: 'Timespinner', description: 'Ma config');

        $this->assertSame('Ma config', $yaml->toArray()['description']);
    }

    public function testToArray_withOptions_buildsGameSection(): void
    {
        $yaml = new PlayerYaml(
            name: 'Jean',
            game: 'A Link to the Past',
            options: [
                new ChoiceOption('accessibility', 'full'),
                new RangeOption('progression_balancing', 50),
                new ToggleOption('swordless', false),
                new ItemListOption('local_items', ['Bombos', 'Ether']),
                new ItemDictOption('start_inventory', ['Pegasus Boots' => 1]),
                new ChoiceOption('smallkey_shuffle', [new Weighted('original_dungeon', 1), new Weighted('any_world', 2)]),
            ],
        );

        $array = $yaml->toArray();

        $this->assertSame('Jean', $array['name']);
        $this->assertSame('A Link to the Past', $array['game']);
        $this->assertArrayHasKey('A Link to the Past', $array);

        $section = $array['A Link to the Past'];
        $this->assertIsArray($section);
        $this->assertSame('full', $section['accessibility']);
        $this->assertSame(50, $section['progression_balancing']);
        $this->assertSame(0, $section['swordless']);
        $this->assertSame(['Bombos', 'Ether'], $section['local_items']);
        $this->assertSame(['Pegasus Boots' => 1], $section['start_inventory']);
        $this->assertSame(['original_dungeon' => 1, 'any_world' => 2], $section['smallkey_shuffle']);
    }

    public function testToYamlString_isValidYaml(): void
    {
        $yaml = new PlayerYaml(
            name: 'Samus',
            game: 'Super Metroid',
            options: [
                new ChoiceOption('accessibility', 'items'),
                new RangeOption('progression_balancing', 30),
            ],
        );

        $yamlString = $yaml->toYamlString();
        $parsed = Yaml::parse($yamlString);

        $this->assertIsArray($parsed);
        /** @var array<string, mixed> $parsed */
        $this->assertSame('Samus', $parsed['name']);
        $this->assertSame('Super Metroid', $parsed['game']);
        $gameSection = $parsed['Super Metroid'] ?? [];
        $this->assertIsArray($gameSection);
        $this->assertSame('items', $gameSection['accessibility']);
    }

    public function testToYamlString_nameFieldParseable(): void
    {
        $yaml = new PlayerYaml(name: 'Player One', game: 'Hollow Knight');
        $parsed = Yaml::parse($yaml->toYamlString());

        $this->assertIsArray($parsed);
        $this->assertSame('Player One', $parsed['name']);
    }

    public function testRangeOption_randomString(): void
    {
        $option = new RangeOption('crystals_needed_for_ganon', 'random-low');
        $this->assertSame('random-low', $option->jsonSerialize());
    }

    public function testToggleOption_true(): void
    {
        $this->assertSame(1, (new ToggleOption('key', true))->jsonSerialize());
    }

    public function testToggleOption_false(): void
    {
        $this->assertSame(0, (new ToggleOption('key', false))->jsonSerialize());
    }

    public function testToggleOption_weighted(): void
    {
        $option = new ToggleOption('swordless', [new Weighted(true, 70), new Weighted(false, 30)]);
        $this->assertSame(['true' => 70, 'false' => 30], $option->jsonSerialize());
    }

    public function testChoiceOption_weighted(): void
    {
        $option = new ChoiceOption('goal', [new Weighted('ganon', 3), new Weighted('fast_ganon', 1)]);
        $this->assertSame(['ganon' => 3, 'fast_ganon' => 1], $option->jsonSerialize());
    }

    public function testRangeOption_weighted(): void
    {
        $option = new RangeOption('logic_percent', [new Weighted(80, 50), new Weighted(95, 10)]);
        $this->assertSame(['80' => 50, '95' => 10], $option->jsonSerialize());
    }
}
