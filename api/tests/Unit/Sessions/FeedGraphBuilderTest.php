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
            self::event(SessionFeedEvent::TYPE_ITEM_RECEIVED, 'Alice', 'Bob', 'Super Metroid', 'TUNIC'),
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
            self::event(SessionFeedEvent::TYPE_ITEM_RECEIVED, 'Alice', 'Bob', 'Super Metroid'),
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
            self::event(SessionFeedEvent::TYPE_HINT, 'Alice', 'Bob'),
            self::event(SessionFeedEvent::TYPE_GOAL, 'Bob', 'Bob'),
        ]);

        self::assertSame([], $graph->nodes);
        self::assertSame([], $graph->edges);
        self::assertSame([], $graph->localItemCounts);
    }

    public function testSkipsEventsWithoutBothSides(): void
    {
        $graph = new FeedGraphBuilder()->build([
            self::event(SessionFeedEvent::TYPE_ITEM_RECEIVED, null, 'Bob'),
            self::event(SessionFeedEvent::TYPE_ITEM_RECEIVED, 'Alice', null),
            self::event(SessionFeedEvent::TYPE_ITEM_RECEIVED, '  ', 'Bob'),
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

    /**
     * The type the builder reads must be the type the writer persists. The first cut of this file
     * seeded a literal `'item'`, which the bridge never sends - so the suite was green while every
     * real session produced an empty graph.
     */
    public function testReadsTheTypeThatIsActuallyPersisted(): void
    {
        self::assertContains(SessionFeedEvent::TYPE_ITEM_RECEIVED, SessionFeedEvent::PERSISTED_TYPES);

        $graph = new FeedGraphBuilder()->build([
            self::event('item', 'Alice', 'Bob', 'Super Metroid'),
        ]);

        self::assertSame([], $graph->edges, 'only the persisted item type feeds the graph');
    }

    private static function item(string $sender, string $receiver, string $receiverGame): SessionFeedEvent
    {
        return self::event(SessionFeedEvent::TYPE_ITEM_RECEIVED, $sender, $receiver, $receiverGame);
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
