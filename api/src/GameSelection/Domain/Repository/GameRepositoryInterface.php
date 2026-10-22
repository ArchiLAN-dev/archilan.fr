<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Repository;

use App\GameSelection\Domain\Entity\Game;

interface GameRepositoryInterface
{
    public function findById(string $id): ?Game;

    public function findBySlug(string $slug): ?Game;

    /**
     * @param list<string> $ids
     *
     * @return list<Game>
     */
    public function findByIds(array $ids): array;

    /**
     * @param list<string> $ids
     *
     * @return list<Game>
     */
    public function findByIdsSortedByName(array $ids): array;

    /**
     * @return list<Game>
     */
    public function findAllSortedByName(): array;

    /**
     * Every game id, by name: a sweep that writes each game's introspection loads them one at a time from this,
     * then {@see release()}s each, so its memory does not grow with the catalogue (story 38.20).
     *
     * @return list<string>
     */
    public function findAllIdsSortedByName(): array;

    /** Stop tracking a saved game and what it holds, so a sweep does not keep the whole catalogue loaded. */
    public function release(Game $game): void;

    /**
     * @param list<string> $availabilities
     *
     * @return list<Game>
     */
    public function findByAvailabilitiesSortedByName(array $availabilities): array;

    public function findByApworldHash(string $sha256): ?Game;

    public function save(Game $game): void;

    public function remove(Game $game): void;

    public function flush(): void;
}
