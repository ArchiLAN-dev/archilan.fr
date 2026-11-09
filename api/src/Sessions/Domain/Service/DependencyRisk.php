<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Service;

use App\Sessions\Domain\ValueObject\PlaythroughStep;
use App\Sessions\Domain\ValueObject\RiskyDependency;

/**
 * Story 52.7: who risks waiting for whom, from the presence windows alone - no play time is simulated.
 *
 * A dependency is at risk when a progression item of A, in one of A's first `$sphereLimit` spheres (the spheres
 * where A receives something, in order), lies in B's world, and some half-day of the event has A there and B not.
 * Someone without windows is there the whole event. Two slots of one person never depend on each other.
 */
final class DependencyRisk
{
    public const int DEFAULT_SPHERE_LIMIT = 3;
    public const int MAX_SPHERE_LIMIT = 20;
    private const string TIMEZONE = 'Europe/Paris';

    /**
     * The event cut at noon and midnight (Paris), clipped to its bounds.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    public static function halfDays(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $zone = new \DateTimeZone(self::TIMEZONE);
        $from = $start->setTimezone($zone);
        $end = $end->setTimezone($zone);
        $halves = [];
        while ($from < $end) {
            $midnight = $from->setTime(0, 0);
            $noon = $from->setTime(12, 0);
            $to = $from < $noon ? $noon : $midnight->modify('+1 day');
            $halves[] = [$from, $to < $end ? $to : $end];
            $from = $to;
        }

        return $halves;
    }

    /**
     * @param list<PlaythroughStep>                                                    $steps
     * @param array<string, string>                                                    $people   slot name => person
     * @param array<string, list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>> $windows  person => windows (absent: all the event)
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>                $halfDays
     *
     * @return list<RiskyDependency>
     */
    public static function assess(array $steps, array $people, array $windows, array $halfDays, int $sphereLimit = self::DEFAULT_SPHERE_LIMIT): array
    {
        $early = self::earlySpheres($steps, $sphereLimit);
        $risks = [];
        foreach ($steps as $step) {
            $waiting = $people[$step->receiver] ?? null;
            $holding = $people[$step->finder] ?? null;
            if (null === $waiting || null === $holding || $waiting === $holding || !isset($early[$step->receiver][$step->sphere])) {
                continue;
            }
            $atRisk = [];
            foreach ($halfDays as $index => $half) {
                if (self::there($windows[$waiting] ?? null, $half) && !self::there($windows[$holding] ?? null, $half)) {
                    $atRisk[] = $index;
                }
            }
            if ([] !== $atRisk) {
                $risks[] = new RiskyDependency($waiting, $holding, $step->receiver, $step->finder, $step->item, $step->location, $step->sphere, $atRisk);
            }
        }

        return $risks;
    }

    /**
     * Each receiver's first `$limit` spheres, as sets.
     *
     * @param list<PlaythroughStep> $steps
     *
     * @return array<string, array<int, true>>
     */
    private static function earlySpheres(array $steps, int $limit): array
    {
        $spheres = [];
        foreach ($steps as $step) {
            $spheres[$step->receiver][$step->sphere] = true;
        }
        $early = [];
        foreach ($spheres as $receiver => $set) {
            $numbers = array_keys($set);
            sort($numbers);
            $early[$receiver] = array_fill_keys(array_slice($numbers, 0, max(1, $limit)), true);
        }

        return $early;
    }

    /**
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>|null $windows null or empty: there all along
     * @param array{0: \DateTimeImmutable, 1: \DateTimeImmutable}            $half
     */
    private static function there(?array $windows, array $half): bool
    {
        if (null === $windows || [] === $windows) {
            return true;
        }
        foreach ($windows as [$from, $to]) {
            if ($from < $half[1] && $to > $half[0]) {
                return true;
            }
        }

        return false;
    }
}
