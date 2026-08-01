<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\Support\FeedGraphBuilder;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use PHPUnit\Framework\TestCase;

/**
 * Story 9.48: the exchange graph now describes what was actually played.
 */
final class FeedGraphBuilderTest extends TestCase
{
    public function testAggregatesOneEdgePerOrderedPair(): void
    {
        $graph = new FeedGraphBuilder()->build([
            self::item('Alice', 'Bob', 'Super Metroid'),
            self::item('Alice', 'Bob', 'Super Metroid'),
            self::item('Bob', 'Alice', 'TUNIC'),
        ]);

        $edges = [];
        foreach ($graph->edges as $edge) {
            $edges[$edge->fromSlotName.'->'.$edge->toSlotName] = $edge->count;
        }

        self::assertSame(['Alice->Bob' => 2, 'Bob->Alice' => 1], $edges);
    }

    public function testSelfSendsBecomeLocalItemsNotEdges(): void
    {
        $graph = new FeedGraphBuilder()->build([
            self::item('Alice', 'Alice', 'TUNIC'),
            self::item('Alice', 'Alice', 'TUNIC'),
            self::item('Alice', 'Bob', 'Super Metroid'),
        ]);

        self::assertSame(['Alice' => 2], $graph->localItemCounts);
        self::assertCount(1, $graph->edges);
    }

    public function testGamesComeFromBothSidesOfTheEvent(): void
    {
        $graph = new FeedGraphBuilder()->build([
            self::event('item', 'Alice', 'Bob', 'Super Metroid', 'TUNIC'),
        ]);

        $games = [];
        foreach ($graph->nodes as $node) {
            $games[$node->slotName] = $node->game;
        }

        self::assertSame('Super Metroid', $games['Bob']);
        self::assertSame('TUNIC', $games['Alice']);
    }

    public function testAnUnknownGameStaysEmptyRatherThanInvented(): void
    {
        $graph = new FeedGraphBuilder()->build([
            self::event('item', 'Alice', 'Bob', 'Super Metroid'),
        ]);

        $games = [];
        foreach ($graph->nodes as $node) {
            $games[$node->slotName] = $node->game;
        }

        self::assertSame('Super Metroid', $games['Bob']);
        self::assertSame('', $games['Alice'], 'the feed never carried Alice game');
    }

    public function testIgnoresNonItemEvents(): void
    {
        $graph = new FeedGraphBuilder()->build([
            self::event('hint', 'Alice', 'Bob'),
            self::event('goal', 'Bob', 'Bob'),
        ]);

        self::assertSame([], $graph->nodes);
        self::assertSame([], $graph->edges);
        self::assertSame([], $graph->localItemCounts);
    }

    public function testSkipsEventsWithoutBothSides(): void
    {
        $graph = new FeedGraphBuilder()->build([
            self::event('item', null, 'Bob'),
            self::event('item', 'Alice', null),
            self::event('item', '  ', 'Bob'),
        ]);

        self::assertSame([], $graph->edges);
        self::assertSame([], $graph->nodes);
    }

    public function testEmptyFeedYieldsAnEmptyGraph(): void
    {
        $graph = new FeedGraphBuilder()->build([]);

        self::assertSame([], $graph->nodes);
        self::assertSame([], $graph->edges);
        self::assertSame([], $graph->localItemCounts);
    }

    private static function item(string $sender, string $receiver, string $receiverGame): SessionFeedEvent
    {
        return self::event('item', $sender, $receiver, $receiverGame);
    }

    private static function event(
        string $type,
        ?string $sender,
        ?string $receiver,
        ?string $receiverGame = null,
        ?string $senderGame = null,
    ): SessionFeedEvent {
        return new SessionFeedEvent(
            bin2hex(random_bytes(8)),
            'sess-1',
            $type,
            'text',
            new \DateTimeImmutable('2026-08-01T12:00:00+00:00'),
            null,
            'Item',
            null,
            null,
            'Location',
            null,
            $sender,
            $senderGame,
            null,
            $receiver,
            $receiverGame,
        );
    }
}
