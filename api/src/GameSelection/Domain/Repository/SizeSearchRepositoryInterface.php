<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Repository;

use App\GameSelection\Domain\Entity\SizeSearch;

/** Story 56.7: each player's last size search per game. */
interface SizeSearchRepositoryInterface
{
    public function findById(string $id): ?SizeSearch;

    public function save(SizeSearch $search): void;
}
