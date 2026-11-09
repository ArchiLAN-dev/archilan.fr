<?php

declare(strict_types=1);

namespace App\Registrations\Domain\Service;

/**
 * Story 52.2: the rules of a member's presence windows on an event. Pure: the caller hands the windows and the
 * event's dates, and gets back the first broken rule, or null.
 */
final class PresenceSchedule
{
    public const int MAX_WINDOWS = 10;

    public const string INVERTED = 'presence_window_inverted';
    public const string OUTSIDE_EVENT = 'presence_window_outside_event';
    public const string OVERLAP = 'presence_window_overlap';
    public const string TOO_MANY = 'presence_window_too_many';

    /**
     * @param list<array{\DateTimeImmutable, \DateTimeImmutable}> $windows (arrival, departure) pairs, in any order
     *
     * @return string|null the code of the first broken rule; windows that only touch do not overlap
     */
    public static function problem(array $windows, \DateTimeImmutable $eventStartsAt, \DateTimeImmutable $eventEndsAt): ?string
    {
        if (count($windows) > self::MAX_WINDOWS) {
            return self::TOO_MANY;
        }

        foreach ($windows as [$startsAt, $endsAt]) {
            if ($startsAt >= $endsAt) {
                return self::INVERTED;
            }
            if ($startsAt < $eventStartsAt || $endsAt > $eventEndsAt) {
                return self::OUTSIDE_EVENT;
            }
        }

        $sorted = $windows;
        usort($sorted, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        for ($i = 1, $n = count($sorted); $i < $n; ++$i) {
            if ($sorted[$i][0] < $sorted[$i - 1][1]) {
                return self::OVERLAP;
            }
        }

        return null;
    }

    /** The message shown for a broken rule. */
    public static function message(string $problem): string
    {
        return match ($problem) {
            self::INVERTED => 'Une plage doit finir après son début.',
            self::OUTSIDE_EVENT => 'Les plages doivent rester dans les dates de l\'événement.',
            self::OVERLAP => 'Deux plages se chevauchent.',
            self::TOO_MANY => sprintf('%d plages au maximum.', self::MAX_WINDOWS),
            default => 'Plages invalides.',
        };
    }
}
