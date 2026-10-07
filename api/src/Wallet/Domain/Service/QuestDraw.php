<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Service;

use Random\Randomizer;

/**
 * The quests of a week (story 41.15): the pinned ones first, then a draw without repeats among the candidates
 * until the week has `count` quests. Pure: the randomizer is handed in, so a test seeds it.
 *
 * Story 41.18: each candidate has a weight (a weight-3 quest comes out three times as often as a weight-1 one),
 * and the quests served the week before are left aside - drawn only if the others cannot fill the week.
 */
final class QuestDraw
{
    /**
     * @param list<string>       $pinned     quest ids pinned to the week, in order
     * @param array<string, int> $candidates quest id => weight, the quests that may be drawn
     * @param list<string>       $recent     quest ids served the week before
     *
     * @return list<string> the quest ids drawn, pinned ones excluded
     */
    public static function draw(array $pinned, array $candidates, int $count, Randomizer $randomizer, array $recent = []): array
    {
        $open = array_diff_key($candidates, array_flip($pinned));
        $missing = min($count - \count($pinned), \count($open));
        if ($missing <= 0) {
            return [];
        }

        $fresh = array_diff_key($open, array_flip($recent));
        $drawn = self::weighted($fresh, $missing, $randomizer);
        if (\count($drawn) < $missing) {
            $drawn = [...$drawn, ...self::weighted(array_intersect_key($open, array_flip($recent)), $missing - \count($drawn), $randomizer)];
        }

        return $drawn;
    }

    /**
     * Up to `$take` ids, without repeats, each pick weighted among those left.
     *
     * @param array<string, int> $pool id => weight
     *
     * @return list<string>
     */
    private static function weighted(array $pool, int $take, Randomizer $randomizer): array
    {
        $drawn = [];
        while (\count($drawn) < $take && [] !== $pool) {
            $roll = $randomizer->getInt(1, array_sum(array_map(static fn (int $weight): int => max(1, $weight), $pool)));
            foreach ($pool as $id => $weight) {
                $roll -= max(1, $weight);
                if ($roll <= 0) {
                    // An all-digit id comes back from the array keys as an int.
                    $drawn[] = \sprintf('%s', $id);
                    unset($pool[$id]);
                    break;
                }
            }
        }

        return $drawn;
    }
}
