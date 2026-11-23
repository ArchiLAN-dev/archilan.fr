<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Support\YamlOptionValue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Story 56.7: the value a YAML gives a numeric option, and the YAML with another one.
 */
final class YamlOptionValueTest extends TestCase
{
    private const string YAML = <<<'YAML'
        name: Jean
        game: Hollow Knight
        Hollow Knight:
          GrubHuntGoal:
            12: 10
            30: 40
            random: 0
          Tokens: 80
          Odd: random
          start_inventory: {}
          exclude_locations: []
        YAML;

    public function testItReadsAPlainOrWeightedValueAndFallsBackOnTheDefault(): void
    {
        self::assertSame(30, YamlOptionValue::current(self::YAML, 'Hollow Knight', 'GrubHuntGoal', 1, 46, 46));
        self::assertSame(80, YamlOptionValue::current(self::YAML, 'Hollow Knight', 'Tokens', 1, 200, 50));
        self::assertSame(7, YamlOptionValue::current(self::YAML, 'Hollow Knight', 'Odd', 1, 10, 7));
        self::assertSame(5, YamlOptionValue::current(self::YAML, 'Hollow Knight', 'Missing', 1, 10, 5));
        // Bounded by the option.
        self::assertSame(46, YamlOptionValue::current(self::YAML, 'Hollow Knight', 'Tokens', 1, 46, null));
        self::assertNull(YamlOptionValue::current(self::YAML, 'Hollow Knight', 'Odd', 1, 10, null));
        self::assertNull(YamlOptionValue::current('not: [yaml', 'Hollow Knight', 'Tokens', 1, 10, 5));
    }

    public function testItSetsOneValueAndKeepsTheRest(): void
    {
        $yaml = YamlOptionValue::with(self::YAML, 'Hollow Knight', 'GrubHuntGoal', 20);

        self::assertIsString($yaml);
        $doc = Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP);
        self::assertInstanceOf(\stdClass::class, $doc);
        $section = $doc->{'Hollow Knight'};
        self::assertInstanceOf(\stdClass::class, $section);
        self::assertSame(20, $section->GrubHuntGoal);
        self::assertSame(80, $section->Tokens);
        // An empty mapping stays a mapping, an empty list a list.
        self::assertInstanceOf(\stdClass::class, $section->start_inventory);
        self::assertSame([], $section->exclude_locations);
        self::assertNull(YamlOptionValue::with(self::YAML, 'Celeste', 'Tokens', 1));
    }
}
