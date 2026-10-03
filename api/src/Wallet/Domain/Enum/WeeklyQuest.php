<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Enum;

/**
 * The quests of the week (story 41.6, decision 4 of epic 41): the same three for everyone, 100 pelles at most a
 * week. They only count games actually played (checks, goals), so a second account earns nothing.
 */
enum WeeklyQuest: string
{
    case ReachAGoal = 'reach_a_goal';
    case PlayWithSomeoneNew = 'play_with_someone_new';
    case PlayAWeekly = 'play_a_weekly';

    public function reward(): int
    {
        return match ($this) {
            self::ReachAGoal => 40,
            self::PlayWithSomeoneNew, self::PlayAWeekly => 30,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ReachAGoal => 'Atteindre un goal',
            self::PlayWithSomeoneNew => 'Jouer avec quelqu\'un de nouveau',
            self::PlayAWeekly => 'Faire une hebdo',
        };
    }
}
