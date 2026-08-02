<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

use App\Sessions\Domain\Entity\SessionFeedEvent;

/**
 * Builds the item-exchange graph from what actually happened during the session (story 9.48).
 *
 * The recap used to parse the generation spoiler, which describes the SEED: every placement
 * decided at generation time, including items nobody ever found. The persisted feed describes
 * the SESSION: every item actually sent, by whom, to whom. Two consequences, both wanted -
 * an abandoned run shows the graph of what was played rather than of what was placed, and
 * race seeds, which carry no spoiler at all, finally get a recap.
 *
 * Pure: takes the events, returns data. Slot ids and goal times are attached later by the
 * handler, exactly as before.
 */
final class FeedGraphBuilder
{
    /**
     * @param list<SessionFeedEvent> $events
     */
    public function build(array $events): RecapGraph
    {
        /** @var array<string, string> $gameBySlotName */
        $gameBySlotName = [];
        /** @var array<string, array<string, int>> $edgeCounts from => to => count */
        $edgeCounts = [];
        /** @var array<string, array<string, int>> $edgeProgression from => to => count */
        $edgeProgression = [];
        /** @var array<string, int> $localItemCounts */
        $localItemCounts = [];
        /** @var array<string, int> $localProgression */
        $localProgression = [];

        foreach ($events as $event) {
            if (SessionFeedEvent::TYPE_ITEM_RECEIVED !== $event->getType()) {
                continue;
            }

            $from = self::slotName($event->getSenderName());
            $to = self::slotName($event->getReceiverName());
            if (null === $from || null === $to) {
                continue;
            }

            // A feed event names both sides' games; a slot whose game the feed never carried
            // keeps an empty one rather than an invented one.
            self::rememberGame($gameBySlotName, $from, $event->getSenderGame());
            self::rememberGame($gameBySlotName, $to, $event->getReceiverGame());

            $isProgression = self::isProgression($event->getItemFlags());

            if ($from === $to) {
                $localItemCounts[$from] = ($localItemCounts[$from] ?? 0) + 1;
                $localProgression[$from] = ($localProgression[$from] ?? 0) + ($isProgression ? 1 : 0);
                continue;
            }

            $edgeCounts[$from][$to] = ($edgeCounts[$from][$to] ?? 0) + 1;
            $edgeProgression[$from][$to] = ($edgeProgression[$from][$to] ?? 0) + ($isProgression ? 1 : 0);
        }

        $slotNames = array_keys($gameBySlotName);
        sort($slotNames);

        $nodes = [];
        foreach ($slotNames as $slotName) {
            $nodes[] = new RecapNode($slotName, $gameBySlotName[$slotName]);
        }

        $edges = [];
        foreach ($edgeCounts as $from => $targets) {
            foreach ($targets as $to => $count) {
                $edges[] = new RecapEdge($from, $to, $count, $edgeProgression[$from][$to] ?? 0);
            }
        }

        return new RecapGraph($nodes, $edges, $localItemCounts, $localProgression);
    }

    /**
     * AP classification bit 1 = progression. A null flag means the bridge that recorded the event
     * predates story 32.9 and simply did not say - which is not the same as "filler", hence the
     * front only offers the progression filter when the run actually carries flags.
     */
    private static function isProgression(?int $flags): bool
    {
        return null !== $flags && 1 === ($flags & 1);
    }

    /**
     * @param array<string, string> $gameBySlotName
     */
    private static function rememberGame(array &$gameBySlotName, string $slotName, ?string $game): void
    {
        $trimmed = null === $game ? '' : trim($game);
        if ('' !== $trimmed) {
            $gameBySlotName[$slotName] = $trimmed;

            return;
        }
        $gameBySlotName[$slotName] ??= '';
    }

    private static function slotName(?string $raw): ?string
    {
        $trimmed = null === $raw ? '' : trim($raw);

        return '' === $trimmed ? null : $trimmed;
    }
}
