<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Service;

use Random\Randomizer;

/**
 * The quests of a week (story 41.15): the pinned ones first, then a draw without repeats among the candidates
 * until the week has `count` quests. Pure: the randomizer is handed in, so a test seeds it.
 */
final class QuestDraw
{
    /**
     * @param list<string> $pinned     quest ids pinned to the week, in order
     * @param list<string> $candidates quest ids that may be drawn
     *
     * @return list<string> the quest ids drawn, pinned ones excluded
     */
    public static function draw(array $pinned, array $candidates, int $count, Randomizer $randomizer): array
    {
        $open = array_values(array_diff(array_unique($candidates), $pinned));
        $missing = min($count - \count($pinned), \count($open));
        if ($missing <= 0) {
            return [];
        }

        // A shuffle then the head: no repeats, and the week does not always open on the same quest.
        $drawn = [];
        foreach ($randomizer->shuffleArray($open) as $questId) {
            if (\is_string($questId)) {
                $drawn[] = $questId;
            }
        }

        return \array_slice($drawn, 0, $missing);
    }
}
