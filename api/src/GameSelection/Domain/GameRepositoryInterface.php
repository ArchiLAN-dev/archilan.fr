<?php

declare(strict_types=1);

namespace App\GameSelection\Domain;

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
