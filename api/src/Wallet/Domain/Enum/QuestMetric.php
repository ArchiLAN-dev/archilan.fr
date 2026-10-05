<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Enum;

/**
 * What a quest objective counts over the week (story 41.15), like the facts of the achievements: the set is code
 * defined, each read from what was actually played (checks, goals), so a second account earns nothing.
 */
enum QuestMetric: string
{
    case Goals = 'goals';
    case Checks = 'checks';
    case Weeklies = 'weeklies';
    case NewPartners = 'newPartners';
    case Sessions = 'sessions';
    case DistinctGames = 'distinctGames';

    public function label(): string
    {
        return match ($this) {
            self::Goals => 'Goals atteints',
            self::Checks => 'Checks faits',
            self::Weeklies => 'Hebdos jouées',
            self::NewPartners => 'Nouveaux partenaires de jeu',
            self::Sessions => 'Parties jouées',
            self::DistinctGames => 'Jeux différents joués',
        };
    }

    /** The unit of a progress bar, « 1 / 2 goals ». */
    public function unit(): string
    {
        return match ($this) {
            self::Goals => 'goals',
            self::Checks => 'checks',
            self::Weeklies => 'hebdos',
            self::NewPartners => 'nouveaux partenaires',
            self::Sessions => 'parties',
            self::DistinctGames => 'jeux',
        };
    }
}
