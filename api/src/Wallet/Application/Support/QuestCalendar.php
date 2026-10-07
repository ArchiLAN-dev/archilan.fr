<?php

declare(strict_types=1);

namespace App\Wallet\Application\Support;

use App\Wallet\Domain\ValueObject\QuestWeek;

/**
 * The weeks an admin plans quests for (story 41.15): the current one and the 8 coming ones.
 */
final class QuestCalendar
{
    public const int COMING_WEEKS = 8;

    /** @return list<QuestWeek> the current week first */
    public static function plannable(\DateTimeImmutable $now): array
    {
        $week = QuestWeek::containing($now);
        $weeks = [];
        for ($i = 0; $i <= self::COMING_WEEKS; ++$i) {
            $weeks[] = $week;
            $week = $week->next();
        }

        return $weeks;
    }

    /** @return list<string> */
    public static function plannableKeys(\DateTimeImmutable $now): array
    {
        return array_map(static fn (QuestWeek $week): string => $week->key, self::plannable($now));
    }
}
