<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Repository;

use App\GameSelection\Domain\Entity\GameListEntry;
use App\GameSelection\Domain\Enum\GameListKind;

interface GameListRepositoryInterface
{
    /**
     * The game ids this player put on one of their lists (story 28.13).
     *
     * @return list<string>
     */
    public function findGameIds(string $userId, GameListKind $kind): array;

    public function find(string $userId, string $gameId, GameListKind $kind): ?GameListEntry;

    public function save(GameListEntry $entry): void;

    public function delete(GameListEntry $entry): void;
}
