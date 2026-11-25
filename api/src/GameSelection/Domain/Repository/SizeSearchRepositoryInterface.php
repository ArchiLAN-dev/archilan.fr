<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Repository;

use App\GameSelection\Domain\Entity\SizeSearch;

/** Story 56.7: each player's last size search per game. */
interface SizeSearchRepositoryInterface
{
    public function findById(string $id): ?SizeSearch;

    public function save(SizeSearch $search): void;

    /**
     * Runs `$work` on the search, read fresh and locked until `$work` is done and saved: a restart or a second step of
     * the same search waits instead of writing over it (fixed after the 1.1.0 review). Nothing runs for an unknown id.
     *
     * @param \Closure(SizeSearch): void $work
     */
    public function withLock(string $id, \Closure $work): void;
}
