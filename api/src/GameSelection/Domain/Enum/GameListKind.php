<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Enum;

/**
 * Which of a player's game lists a row belongs to (story 28.13).
 *
 * The lists share a storage keyed by (player, game, kind); they deliberately do not share a
 * meaning. `Owned` is the one the catalog's "mes jeux" filter reads, unioned with a coupled Steam
 * library. A further list is a case here plus its own surface - never a second table with the same
 * four columns.
 */
enum GameListKind: string
{
    case Owned = 'owned';
}
