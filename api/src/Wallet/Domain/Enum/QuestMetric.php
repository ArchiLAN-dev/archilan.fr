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
    // Story 41.64: items traded between members, time played and games discovered.
    case ItemsSent = 'itemsSent';
    case ItemsReceived = 'itemsReceived';
    case PlayMinutes = 'playMinutes';
    case NewGames = 'newGames';
    // Story 41.65: playing together, and the rest of the site.
    case DuelsWon = 'duelsWon';
    case Superlatives = 'superlatives';
    case FriendsPlayedWith = 'friendsPlayedWith';
    case ReturningPartners = 'returningPartners';
    case BountiesWon = 'bountiesWon';
    case BlindTestSources = 'blindTestSources';

    public function label(): string
    {
        return match ($this) {
            self::Goals => 'Goals atteints',
            self::Checks => 'Checks faits',
            self::Weeklies => 'Hebdos terminées',
            self::NewPartners => 'Nouveaux partenaires de jeu',
            self::Sessions => 'Parties jouées',
            self::DistinctGames => 'Jeux différents joués',
            self::ItemsSent => 'Items envoyés à d\'autres membres',
            self::ItemsReceived => 'Items reçus d\'autres membres',
            self::PlayMinutes => 'Temps de jeu',
            self::NewGames => 'Jeux jamais joués',
            self::DuelsWon => 'Duels hebdo gagnés',
            self::Superlatives => 'Superlatifs de récap remportés',
            self::FriendsPlayedWith => 'Amis avec qui jouer',
            self::ReturningPartners => 'Partenaires retrouvés',
            self::BountiesWon => 'Primes remportées',
            self::BlindTestSources => 'Sources trouvées au blind test',
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
            self::ItemsSent => 'items envoyés',
            self::ItemsReceived => 'items reçus',
            self::PlayMinutes => 'de jeu',
            self::NewGames => 'nouveaux jeux',
            self::DuelsWon => 'duels gagnés',
            self::Superlatives => 'superlatifs',
            self::FriendsPlayedWith => 'amis',
            self::ReturningPartners => 'partenaires retrouvés',
            self::BountiesWon => 'primes',
            self::BlindTestSources => 'sources trouvées',
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
            self::ItemsSent => 'item envoyé',
            self::ItemsReceived => 'item reçu',
            self::PlayMinutes => 'de jeu',
            self::NewGames => 'nouveau jeu',
            self::DuelsWon => 'duel gagné',
            self::Superlatives => 'superlatif',
            self::FriendsPlayedWith => 'ami',
            self::ReturningPartners => 'partenaire retrouvé',
            self::BountiesWon => 'prime',
            self::BlindTestSources => 'source trouvée',
        };
    }

    /** The unit that goes with a target: singular for 1, plural above. */
    public function unitFor(int $target): string
    {
        return 1 === $target ? $this->unitOne() : $this->unit();
    }

    /** Story 41.64: what the admin form says under the type, so the count holds no surprise. */
    public function help(): string
    {
        return match ($this) {
            self::Goals => 'Goals atteints en partie ou en hebdo.',
            self::Checks => 'Checks faits en partie ou en hebdo.',
            self::Weeklies => 'Hebdos terminées, comptées la semaine où le goal tombe.',
            self::NewPartners => 'Membres avec qui il n\'avait jamais joué, chacun ayant fait un check dans la partie.',
            self::Sessions => 'Parties où il a fait au moins un check (hors hebdos).',
            self::DistinctGames => 'Jeux différents où il a fait au moins un check (hors hebdos).',
            self::ItemsSent => 'Items trouvés pour le slot d\'un autre membre, hors release et collect.',
            self::ItemsReceived => 'Items reçus du slot d\'un autre membre, hors release et collect.',
            self::PlayMinutes => 'Temps connecté sur les slots où il a fait au moins un check (hors hebdos). Saisi en heures.',
            self::NewGames => 'Jeux où il fait son tout premier check sur le site, parties et hebdos comprises.',
            self::DuelsWon => 'Duels gagnés contre un adversaire qui a joué l\'hebdo. Un duel se règle à la fin de son hebdo : il peut compter la semaine suivante.',
            self::Superlatives => 'Superlatifs des récaps construits dans la semaine, parties d\'au moins 3 membres.',
            self::FriendsPlayedWith => 'Amis différents avec qui il a fait une partie, chacun y ayant fait un check.',
            self::ReturningPartners => 'Membres avec qui il avait déjà joué, retrouvés dans une partie où chacun fait un check.',
            self::BountiesWon => 'Primes en or posées par un autre membre et remportées dans la semaine.',
            self::BlindTestSources => 'Sources trouvées pendant un blind test d\'événement.',
        };
    }

    /**
     * Story 41.65: what an objective of this metric may aim at (story 41.18). Only the metrics read from sessions
     * can be narrowed, and the superlatives only to an event: a recap has no single game.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return match ($this) {
            self::Goals, self::Checks, self::Sessions, self::ItemsSent, self::ItemsReceived, self::PlayMinutes => ['game', 'event', 'platform', 'recent'],
            self::Superlatives => ['event'],
            default => [],
        };
    }

    /** Story 41.64: a count of minutes, shown in hours (« 2 h 30 / 6 h »). */
    public function isDuration(): bool
    {
        return self::PlayMinutes === $this;
    }

    /** « 2 goals », « 6 h de jeu »: an amount of the metric in words. */
    public function describe(int $amount): string
    {
        if ($this->isDuration()) {
            return sprintf('%s %s', self::hours($amount), $this->unit());
        }

        return sprintf('%d %s', $amount, $this->unitFor($amount));
    }

    /** « 6 h », « 2 h 30 », « 45 min ». */
    public static function hours(int $minutes): string
    {
        $h = intdiv(max(0, $minutes), 60);
        $m = max(0, $minutes) % 60;
        if (0 === $h) {
            return sprintf('%d min', $m);
        }

        return 0 === $m ? sprintf('%d h', $h) : sprintf('%d h %02d', $h, $m);
    }
}
