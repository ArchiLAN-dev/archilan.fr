<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Service;

/**
 * Story 52.4: what the Passeur costs, and what the absent player gets. Pure.
 *
 * The price starts at the base when the block begins and drops by `decayPercent` of the base every quarter of an
 * hour of block, down to `floorPercent` of the base, rounded up. The absent player's share is rounded down.
 */
final class PasseurPrice
{
    public const int STEP_SECONDS = 900;

    public static function at(int $basePrice, int $decayPercent, int $floorPercent, \DateTimeImmutable $blockedSince, \DateTimeImmutable $now): int
    {
        $steps = intdiv(max(0, $now->getTimestamp() - $blockedSince->getTimestamp()), self::STEP_SECONDS);
        $percent = max($floorPercent, 100 - $steps * $decayPercent);

        return max(1, intdiv($basePrice * $percent + 99, 100));
    }

    public static function share(int $pricePaid, int $sharePercent): int
    {
        return intdiv($pricePaid * $sharePercent, 100);
    }

    /**
     * The civil day (Europe/Paris) holding `$now`, as two instants: the daily limit counts the openings in it.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    public static function dayOf(\DateTimeImmutable $now): array
    {
        $start = $now->setTimezone(new \DateTimeZone('Europe/Paris'))->setTime(0, 0);

        return [$start, $start->modify('+1 day')];
    }
}
