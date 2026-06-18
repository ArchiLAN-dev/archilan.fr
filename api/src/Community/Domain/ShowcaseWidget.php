<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * The owner-arrangeable profile showcase widgets (story 30.6). The owner picks which widgets appear and
 * in what order (`CommunityProfile::showcaseLayout`); the frontend renders each from data already on the
 * profile / run history. `currently_playing` (presence) lands with story 30.14.
 */
final class ShowcaseWidget
{
    public const FAVORITE_GAMES = 'favorite_games';
    public const FEATURED_ACHIEVEMENTS = 'featured_achievements';
    public const BEST_RUNS = 'best_runs';
    public const MOST_PLAYED = 'most_played';

    public const ALL = [
        self::FAVORITE_GAMES,
        self::FEATURED_ACHIEVEMENTS,
        self::BEST_RUNS,
        self::MOST_PLAYED,
    ];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
