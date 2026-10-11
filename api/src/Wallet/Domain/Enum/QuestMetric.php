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
            self::Weeklies => 'Hebdos terminées',
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

    /** The unit for one, « 1 goal », « 1 nouveau partenaire ». */
    public function unitOne(): string
    {
        return match ($this) {
            self::Goals => 'goal',
            self::Checks => 'check',
            self::Weeklies => 'hebdo',
            self::NewPartners => 'nouveau partenaire',
            self::Sessions => 'partie',
            self::DistinctGames => 'jeu',
        };
    }

    /** The unit that goes with a target: singular for 1, plural above. */
    public function unitFor(int $target): string
    {
        return 1 === $target ? $this->unitOne() : $this->unit();
    }
}
