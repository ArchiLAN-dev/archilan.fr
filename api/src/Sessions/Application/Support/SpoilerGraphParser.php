<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

/**
 * Turns an Archipelago generation spoiler into an item-exchange {@see RecapGraph}.
 *
 * Pure: string in, DTO out - no IO, no clock, no randomness (AC-D3/AC-A). Built
 * against a real 3-player fixture (see tests). Defensive by contract: a missing,
 * truncated or unexpected spoiler yields whatever nodes/edges could be read (down
 * to an empty graph), never an exception - the recap falls back to stats-only.
 *
 * Spoiler shape it reads:
 *  - Player -> game from the header blocks: `Player <n>: <name>` then `Game: <name>`.
 *  - Edges from the `Locations:` section, one per line:
 *      `<Location> (<Host>): <Item> (<Owner>)`
 *    meaning: when Host reaches the location, Item is sent to Owner - an edge
 *    Host -> Owner. Host == Owner is a local item (self-edge), aggregated apart.
 *
 * Parsing is anchored from the right because location and item names themselves
 * contain parentheses (`Armory Gray Chest (left, back Wall) (Player1): ...`): the
 * owner is always the last `(...)` group, the host the last `(...)` before `): `.
 */
final class SpoilerGraphParser
{
    /**
     * `<location> (<host>): <item> (<owner>)`, greedy on location and item so the
     * host/owner bind to the last parenthesised group on each side.
     */
    private const string LOCATION_LINE = '/^(?P<location>.*)\s+\((?P<host>[^()]+)\):\s+(?P<item>.*)\s+\((?P<owner>[^()]+)\)\s*$/';

    public function parse(string $spoilerContents): RecapGraph
    {
        $lines = preg_split('/\r\n|\r|\n/', $spoilerContents);
        if (false === $lines) {
            return new RecapGraph([], [], []);
        }

        $games = $this->parsePlayerGames($lines);
        [$edges, $localItemCounts] = $this->parseExchanges($lines);

        $nodes = [];
        foreach ($games as $slotName => $game) {
            $nodes[] = new RecapNode($slotName, $game);
        }

        return new RecapGraph($nodes, $edges, $localItemCounts);
    }

    /**
     * @param list<string> $lines
     *
     * @return array<string,string> slotName => game name (insertion-ordered by slot)
     */
    private function parsePlayerGames(array $lines): array
    {
        $games = [];
        $currentSlot = null;

        foreach ($lines as $line) {
            if (1 === preg_match('/^Player\s+\d+:\s*(?P<name>.+?)\s*$/', $line, $m)) {
                $currentSlot = $m['name'];
                $games[$currentSlot] ??= '';

                continue;
            }

            // Only the first `Game:` line inside a block is the game; `Game Mode:`
            // and friends do not match (the colon must follow `Game` directly).
            if (null !== $currentSlot && 1 === preg_match('/^Game:\s*(?P<game>.+?)\s*$/', $line, $m)) {
                $games[$currentSlot] = $m['game'];
                $currentSlot = null;
            }
        }

        return $games;
    }

    /**
     * @param list<string> $lines
     *
     * @return array{0: list<RecapEdge>, 1: array<string,int>}
     */
    private function parseExchanges(array $lines): array
    {
        /** @var array<string,int> $edgeCounts keyed by "from\x00to" */
        $edgeCounts = [];
        /** @var array<string,int> $localCounts keyed by slotName */
        $localCounts = [];

        $inLocations = false;
        foreach ($lines as $line) {
            $line = rtrim($line);

            if (!$inLocations) {
                if (1 === preg_match('/^Locations:\s*$/', $line)) {
                    $inLocations = true;
                }

                continue;
            }

            if ('' === $line) {
                continue;
            }

            if (1 !== preg_match(self::LOCATION_LINE, $line, $m)) {
                // A bare header (`Playthrough:`, `Paths:`, ...) ends the section;
                // any other non-matching line is tolerated and skipped.
                if (1 === preg_match('/^\S.*:$/', $line)) {
                    break;
                }

                continue;
            }

            $host = $m['host'];
            $owner = $m['owner'];

            if ($host === $owner) {
                $localCounts[$host] = ($localCounts[$host] ?? 0) + 1;

                continue;
            }

            $key = $host."\x00".$owner;
            $edgeCounts[$key] = ($edgeCounts[$key] ?? 0) + 1;
        }

        $edges = [];
        foreach ($edgeCounts as $key => $count) {
            [$from, $to] = explode("\x00", $key, 2);
            $edges[] = new RecapEdge($from, $to, $count);
        }

        return [$edges, $localCounts];
    }
}
