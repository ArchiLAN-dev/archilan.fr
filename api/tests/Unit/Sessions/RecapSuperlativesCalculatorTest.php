<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\RecapEdge;
use App\Sessions\Application\RecapGraph;
use App\Sessions\Application\RecapSuperlative;
use App\Sessions\Application\RecapSuperlativesCalculator;
use PHPUnit\Framework\TestCase;

final class RecapSuperlativesCalculatorTest extends TestCase
{
    public function testAwardsEachSuperlativeToTheRightSlot(): void
    {
        // Alice spreads to 3 slots (biggest hub) but sends few; Bob dumps 10 on
        // one slot (most generous by volume). Dan finishes first, Carol last.
        $graph = new RecapGraph(
            nodes: [],
            edges: [
                new RecapEdge('Alice', 'Bob', 1),
                new RecapEdge('Alice', 'Carol', 1),
                new RecapEdge('Alice', 'Dan', 1),
                new RecapEdge('Bob', 'Carol', 10),
            ],
            localItemCounts: [],
        );
        $goals = [
            'Alice' => new \DateTimeImmutable('2026-01-01T10:00:00+00:00'),
            'Bob' => new \DateTimeImmutable('2026-01-01T12:00:00+00:00'),
            'Carol' => new \DateTimeImmutable('2026-01-01T14:00:00+00:00'),
            'Dan' => new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            'Eve' => null,
        ];

        $byKey = $this->indexByKey((new RecapSuperlativesCalculator())->calculate($graph, $goals));

        self::assertSame('Bob', $byKey['most_generous']->slotName);
        self::assertSame(10, $byKey['most_generous']->value);
        self::assertSame('Alice', $byKey['biggest_hub']->slotName);
        self::assertSame(3, $byKey['biggest_hub']->value);
        self::assertSame('Dan', $byKey['first_to_goal']->slotName);
        self::assertSame('2026-01-01T09:00:00+00:00', $byKey['first_to_goal']->value);
        self::assertSame('Carol', $byKey['longest_road']->slotName);
        self::assertSame('2026-01-01T14:00:00+00:00', $byKey['longest_road']->value);
    }

    public function testOmitsTimeBasedSuperlativesWhenNobodyReachedGoal(): void
    {
        $graph = new RecapGraph(
            nodes: [],
            edges: [new RecapEdge('Alice', 'Bob', 3)],
            localItemCounts: [],
        );
        $goals = ['Alice' => null, 'Bob' => null];

        $keys = array_keys($this->indexByKey((new RecapSuperlativesCalculator())->calculate($graph, $goals)));
        sort($keys);

        self::assertSame(['biggest_hub', 'most_generous'], $keys);
    }

    public function testOmitsExchangeSuperlativesWhenThereAreNoEdges(): void
    {
        $graph = new RecapGraph(nodes: [], edges: [], localItemCounts: ['Alice' => 5]);
        $goals = ['Alice' => new \DateTimeImmutable('2026-01-01T10:00:00+00:00')];

        $keys = array_keys($this->indexByKey((new RecapSuperlativesCalculator())->calculate($graph, $goals)));
        sort($keys);

        self::assertSame(['first_to_goal', 'longest_road'], $keys);
    }

    public function testEmptyRunYieldsNoSuperlatives(): void
    {
        $graph = new RecapGraph(nodes: [], edges: [], localItemCounts: []);

        self::assertSame([], (new RecapSuperlativesCalculator())->calculate($graph, []));
    }

    public function testTiesAreBrokenByFirstInsertion(): void
    {
        $graph = new RecapGraph(
            nodes: [],
            edges: [
                new RecapEdge('Alice', 'Carol', 4),
                new RecapEdge('Bob', 'Carol', 4),
            ],
            localItemCounts: [],
        );

        $byKey = $this->indexByKey((new RecapSuperlativesCalculator())->calculate($graph, []));

        self::assertSame('Alice', $byKey['most_generous']->slotName);
    }

    /**
     * @param list<RecapSuperlative> $superlatives
     *
     * @return array<string,RecapSuperlative>
     */
    private function indexByKey(array $superlatives): array
    {
        $byKey = [];
        foreach ($superlatives as $superlative) {
            $byKey[$superlative->key] = $superlative;
        }

        return $byKey;
    }
}
