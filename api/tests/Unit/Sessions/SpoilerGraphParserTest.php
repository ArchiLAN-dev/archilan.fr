<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\Support\RecapGraph;
use App\Sessions\Application\Support\SpoilerGraphParser;
use PHPUnit\Framework\TestCase;

final class SpoilerGraphParserTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../Fixtures/Sessions/sample_AP_Spoiler.txt';

    public function testParsesPlayerGamesFromRealSpoiler(): void
    {
        $graph = $this->parseFixture();

        $games = [];
        foreach ($graph->nodes as $node) {
            $games[$node->slotName] = $node->game;
        }

        self::assertSame(
            [
                'Player1' => "Luigi's Mansion",
                'Player2' => 'Super Mario 64',
                'Player3' => 'The Wind Waker',
            ],
            $games,
        );
    }

    public function testAggregatesExchangeEdgesFromRealSpoiler(): void
    {
        $graph = $this->parseFixture();

        $edges = [];
        foreach ($graph->edges as $edge) {
            $edges[$edge->fromSlotName.'->'.$edge->toSlotName] = $edge->count;
        }
        ksort($edges);

        self::assertSame(
            [
                'Player1->Player2' => 32,
                'Player1->Player3' => 23,
                'Player2->Player1' => 26,
                'Player2->Player3' => 34,
                'Player3->Player1' => 29,
                'Player3->Player2' => 28,
            ],
            $edges,
        );
    }

    public function testCountsLocalItemsSeparatelyFromRealSpoiler(): void
    {
        $graph = $this->parseFixture();

        $local = $graph->localItemCounts;
        ksort($local);

        self::assertSame(['Player1' => 103, 'Player2' => 89, 'Player3' => 52], $local);

        // Every location line is either an exchange or a local item.
        $exchanged = array_sum(array_map(static fn ($e) => $e->count, $graph->edges));
        self::assertSame(416, $exchanged + array_sum($local));
    }

    public function testParsesOwnerFromLastParenGroupWhenNamesContainParentheses(): void
    {
        // Location and item names carry their own parentheses; the host/owner are
        // the last paren group on each side of the colon.
        $spoiler = <<<'TXT'
            Player 1: Alice
            Game:                            Luigi's Mansion
            Player 2: Bob
            Game:                            Super Mario 64

            Locations:

            Armory Gray Chest (left, back Wall) (Alice): Power Star (Bob)
            Huge Flower (Boneyard) (Alice): Emerald (Alice)

            Playthrough:
            TXT;

        $graph = new SpoilerGraphParser()->parse($spoiler);

        self::assertCount(1, $graph->edges);
        self::assertSame('Alice', $graph->edges[0]->fromSlotName);
        self::assertSame('Bob', $graph->edges[0]->toSlotName);
        self::assertSame(1, $graph->edges[0]->count);
        self::assertSame(['Alice' => 1], $graph->localItemCounts);
    }

    public function testSumsRepeatedPairsIntoASingleEdge(): void
    {
        $spoiler = <<<'TXT'
            Player 1: Alice
            Game: A
            Player 2: Bob
            Game: B

            Locations:

            Loc One (Alice): Item X (Bob)
            Loc Two (Alice): Item Y (Bob)
            Loc Three (Bob): Item Z (Alice)
            TXT;

        $graph = new SpoilerGraphParser()->parse($spoiler);

        $edges = [];
        foreach ($graph->edges as $edge) {
            $edges[$edge->fromSlotName.'->'.$edge->toSlotName] = $edge->count;
        }

        self::assertSame(['Alice->Bob' => 2, 'Bob->Alice' => 1], $edges);
    }

    public function testEmptyInputYieldsEmptyGraph(): void
    {
        $graph = new SpoilerGraphParser()->parse('');

        self::assertEmptyGraph($graph);
    }

    public function testUnexpectedInputIsToleratedAsEmptyGraph(): void
    {
        $graph = new SpoilerGraphParser()->parse("not a spoiler at all\n:::\n(garbage)\n");

        self::assertEmptyGraph($graph);
    }

    public function testStopsAtNextSectionHeaderAndIgnoresStrayLines(): void
    {
        $spoiler = <<<'TXT'
            Player 1: Alice
            Game: A
            Player 2: Bob
            Game: B

            Locations:

            Loc One (Alice): Item X (Bob)

            Playthrough:
            Loc Two (Bob): Item Y (Alice)
            TXT;

        $graph = new SpoilerGraphParser()->parse($spoiler);

        // The line after `Playthrough:` must not be read as an exchange.
        self::assertCount(1, $graph->edges);
        self::assertSame('Alice', $graph->edges[0]->fromSlotName);
        self::assertSame('Bob', $graph->edges[0]->toSlotName);
    }

    private function parseFixture(): RecapGraph
    {
        $contents = file_get_contents(self::FIXTURE);
        self::assertNotFalse($contents, 'fixture spoiler must be readable');

        return new SpoilerGraphParser()->parse($contents);
    }

    private static function assertEmptyGraph(RecapGraph $graph): void
    {
        self::assertSame([], $graph->nodes);
        self::assertSame([], $graph->edges);
        self::assertSame([], $graph->localItemCounts);
    }
}
