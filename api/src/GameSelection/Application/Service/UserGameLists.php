<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Service;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Entity\GameListEntry;
use App\GameSelection\Domain\Enum\GameListKind;
use App\GameSelection\Domain\Repository\GameListRepositoryInterface;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The lists a player keeps on ArchiLAN, independently of any Steam coupling (story 28.13).
 *
 * Deliberately separate from the Steam library: the coupling can only recognise titles that carry a
 * `steamAppId`, which most of this catalog does not. The two sources are unioned at display time,
 * never merged in storage - so re-coupling Steam can never wipe what a player marked by hand, and
 * un-marking by hand never fights the coupling.
 *
 * The list kind is a parameter, not a class: every list obeys the same three operations, and one
 * more of them costs a case in {@see GameListKind} rather than a duplicate of this file.
 */
final readonly class UserGameLists
{
    public function __construct(
        private GameListRepositoryInterface $entries,
        private GameRepositoryInterface $games,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<string>
     */
    public function gameIds(string $userId, GameListKind $kind): array
    {
        return $this->entries->findGameIds($userId, $kind);
    }

    /**
     * Puts a game on a list. Idempotent: the (player, game, kind) triple is the row identity, so
     * adding what is already there is a no-op rather than an error - the client should not have to
     * know.
     */
    public function add(string $userId, string $gameId, GameListKind $kind): GameListOutcome
    {
        if (!$this->games->findById($gameId) instanceof Game) {
            return GameListOutcome::GameNotFound;
        }

        if (null === $this->entries->find($userId, $gameId, $kind)) {
            $this->entries->save(GameListEntry::add($userId, $gameId, $kind, $this->clock->now()));
        }

        return GameListOutcome::Added;
    }

    /** Idempotent for the same reason: removing what was never there is a no-op. */
    public function remove(string $userId, string $gameId, GameListKind $kind): GameListOutcome
    {
        $entry = $this->entries->find($userId, $gameId, $kind);
        if (null !== $entry) {
            $this->entries->delete($entry);
        }

        return GameListOutcome::Removed;
    }
}
