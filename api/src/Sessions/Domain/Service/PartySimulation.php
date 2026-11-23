<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Service;

use App\Sessions\Domain\ValueObject\PartySimulationResult;
use App\Sessions\Domain\ValueObject\SimulatedSlot;

/**
 * Story 56.4: a simple, explainable simulation of a party, from the spoiler's playthrough and each slot's playing
 * time at its pace (story 56.2).
 *
 * A slot's time is cut into equal parts, one more than the spheres in which it receives a progression item. Part 0
 * needs nothing; part k starts once the slot has received its items of the spheres up to its k-th one, and once part
 * k-1 is done. An item it finds for someone in sphere s is found halfway through the part that opens sphere s (the
 * first part that holds all its items of the spheres below s), and reaches its receiver at once. Waiting before a
 * part is time in BK, charged to the slot whose item came last.
 *
 * Sphere s only needs items of the spheres below it, so the spheres are played in order and every arrival is known
 * when it is needed. The model ignores moving around a game, hints and the players' choices: an order of magnitude
 * to compare the slots, never a forecast to the minute. Pure: no I/O, no clock.
 */
final class PartySimulation
{
    private const int TOP_WAITS = 2;

    /**
     * @param array<string, float>                                       $workHours slot name => hours of play at its pace
     * @param list<array{sphere: int, finder: string, receiver: string}> $steps     the progression items, by sphere
     */
    public static function run(array $workHours, array $steps): PartySimulationResult
    {
        /** @var array<string, list<array{int, string}>> $incoming receiver => [sphere, finder] */
        $incoming = [];
        /** @var array<int, array<string, true>> $finders sphere => finders */
        $finders = [];
        foreach ($steps as $step) {
            if (!isset($workHours[$step['finder']], $workHours[$step['receiver']]) || $step['sphere'] < 1) {
                continue;
            }
            $incoming[$step['receiver']][] = [$step['sphere'], $step['finder']];
            $finders[$step['sphere']][$step['finder']] = true;
        }
        ksort($finders);

        $state = [];
        foreach ($workHours as $name => $hours) {
            $unlocks = array_values(array_unique(array_map(static fn (array $in): int => $in[0], $incoming[$name] ?? [])));
            sort($unlocks);
            $state[$name] = [
                'unlocks' => $unlocks,
                'part' => max(0.0, $hours) / (count($unlocks) + 1),
                'done' => 0,
                'cursor' => 0.0,
                'starts' => [],
                'bk' => 0.0,
                'waits' => [],
            ];
        }

        /** @var array<string, array<int, float>> $found finder => sphere => when its items leave */
        $found = [];
        foreach (array_keys($finders) as $sphere) {
            foreach (array_keys($finders[$sphere]) as $finder) {
                $part = self::partOpening($state[$finder]['unlocks'], $sphere);
                self::playUpTo($state, $finder, $part, $incoming, $found);
                $found[$finder][$sphere] = $state[$finder]['starts'][$part] + $state[$finder]['part'] / 2;
            }
        }

        $slots = [];
        $end = 0.0;
        $critical = null;
        foreach (array_keys($state) as $name) {
            self::playUpTo($state, $name, count($state[$name]['unlocks']), $incoming, $found);
            $waits = $state[$name]['waits'];
            arsort($waits);
            $finish = $state[$name]['cursor'];
            $slots[$name] = new SimulatedSlot($finish, $state[$name]['bk'], array_slice($waits, 0, self::TOP_WAITS, true));
            if (null === $critical || $finish > $end) {
                $end = $finish;
                $critical = $name;
            }
        }

        return new PartySimulationResult($slots, $end, $critical);
    }

    /**
     * The part in which a slot finds its items of a sphere: the one that follows its last unlock below that sphere.
     *
     * @param list<int> $unlocks
     */
    private static function partOpening(array $unlocks, int $sphere): int
    {
        return count(array_filter($unlocks, static fn (int $u): bool => $u < $sphere));
    }

    /**
     * Plays a slot's parts up to `$last` (included), each after its items have arrived.
     *
     * @param array<string, array{unlocks: list<int>, part: float, done: int, cursor: float, starts: array<int, float>, bk: float, waits: array<string, float>}> $state
     * @param array<string, list<array{int, string}>>                                                                                                            $incoming
     * @param array<string, array<int, float>>                                                                                                                   $found
     */
    private static function playUpTo(array &$state, string $name, int $last, array $incoming, array $found): void
    {
        while ($state[$name]['done'] <= $last) {
            $part = $state[$name]['done'];
            $cursor = $state[$name]['cursor'];
            $ready = 0.0;
            $blocker = null;
            if ($part > 0) {
                $needed = $state[$name]['unlocks'][$part - 1];
                foreach ($incoming[$name] ?? [] as [$sphere, $finder]) {
                    $arrival = $found[$finder][$sphere] ?? 0.0;
                    if ($sphere <= $needed && $arrival > $ready) {
                        $ready = $arrival;
                        $blocker = $finder;
                    }
                }
            }
            $start = max($cursor, $ready);
            if ($start > $cursor && null !== $blocker && $blocker !== $name) {
                $state[$name]['bk'] += $start - $cursor;
                $state[$name]['waits'][$blocker] = ($state[$name]['waits'][$blocker] ?? 0.0) + $start - $cursor;
            }
            $state[$name]['starts'][$part] = $start;
            $state[$name]['cursor'] = $start + $state[$name]['part'];
            ++$state[$name]['done'];
        }
    }
}
