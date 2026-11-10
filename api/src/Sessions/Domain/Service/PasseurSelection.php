<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Service;

use App\Sessions\Domain\ValueObject\PasseurItem;

/**
 * Story 52.3: which chests the Passeur may open for a blocked player, and in which order. Pure.
 *
 * A candidate is a progression item for the buyer, in an unopened location of another world (the bridge only
 * reports the buyer's own items, not received, in other worlds' unopened locations). Story 52.9: any other world,
 * the player of that world being there or not. One copy per item: the one in the latest sphere, so that world's
 * exploration loses the least.
 */
final class PasseurSelection
{
    /**
     * @param list<PasseurItem> $items the buyer's items still owed by other worlds
     *
     * @return list<PasseurItem> one per item id, in the fallback order (see byRank)
     */
    public static function candidates(array $items): array
    {
        $kept = [];
        foreach ($items as $item) {
            if (!$item->progression) {
                continue;
            }
            $current = $kept[$item->itemId] ?? null;
            if (null === $current || self::later($item, $current)) {
                $kept[$item->itemId] = $item;
            }
        }

        return self::byRank(array_values($kept), []);
    }

    /**
     * The candidates, those that open the most of the buyer's locations first, equal gains by location id. A
     * candidate the tracker did not measure comes after the measured ones; those, and all of them when nothing is
     * measured, go earliest sphere first (an untracked world last), then by location id.
     *
     * @param list<PasseurItem>  $candidates
     * @param array<string, int> $gains      locations made reachable, by PasseurItem::key()
     *
     * @return list<PasseurItem>
     */
    public static function byRank(array $candidates, array $gains): array
    {
        usort($candidates, static function (PasseurItem $a, PasseurItem $b) use ($gains): int {
            $gainA = $gains[$a->key()] ?? null;
            $gainB = $gains[$b->key()] ?? null;
            if ($gainA !== $gainB) {
                return ($gainB ?? -1) <=> ($gainA ?? -1);
            }
            if (null !== $gainA) {
                return [$a->locationId, $a->findingSlot] <=> [$b->locationId, $b->findingSlot];
            }

            return [$a->sphere ?? PHP_INT_MAX, $a->locationId, $a->findingSlot] <=> [$b->sphere ?? PHP_INT_MAX, $b->locationId, $b->findingSlot];
        });

        return $candidates;
    }

    /** Whether `$a` sits later than `$b` in the playthrough: a higher sphere, an unknown one last. */
    private static function later(PasseurItem $a, PasseurItem $b): bool
    {
        return [$a->sphere ?? -1, -$a->locationId, -$a->findingSlot] > [$b->sphere ?? -1, -$b->locationId, -$b->findingSlot];
    }
}
