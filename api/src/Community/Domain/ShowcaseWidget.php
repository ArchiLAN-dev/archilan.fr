<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * The owner-arrangeable profile showcase widgets (story 30.6). The owner picks which widgets appear and
 * in what order (`CommunityProfile::showcaseLayout`); the frontend renders each from data already on the
 * profile / run history. `featured_achievements` was retired - the full "Succès" panel already shows every
 * achievement, so the teaser was a duplicate; invalid keys are stripped from existing layouts on next save.
 */
final class ShowcaseWidget
{
    public const FAVORITE_GAMES = 'favorite_games';
    public const BEST_RUNS = 'best_runs';
    public const MOST_PLAYED = 'most_played';

    public const ALL = [
        self::FAVORITE_GAMES,
        self::BEST_RUNS,
        self::MOST_PLAYED,
    ];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
