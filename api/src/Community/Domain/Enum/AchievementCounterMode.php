<?php

declare(strict_types=1);

namespace App\Community\Domain\Enum;

/**
 * How an achievement counter adds up what it counts (story 30.57): everything since the beginning, the most in a
 * single session, or how many of its names were reached at least once.
 */
enum AchievementCounterMode: string
{
    case Total = 'total';
    case MaxSession = 'max_session';
    case Distinct = 'distinct';

    public function label(): string
    {
        return match ($this) {
            self::Total => 'Total',
            self::MaxSession => 'Le plus dans une même partie',
            self::Distinct => 'Noms différents atteints',
        };
    }
}
