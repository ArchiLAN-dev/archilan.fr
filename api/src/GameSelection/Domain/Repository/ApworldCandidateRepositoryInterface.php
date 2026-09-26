<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Repository;

use App\GameSelection\Domain\Entity\ApworldCandidate;

interface ApworldCandidateRepositoryInterface
{
    public function save(ApworldCandidate $candidate): void;

    public function findById(string $id): ?ApworldCandidate;

    /**
     * The candidate in test for this game, if any. There is at most one: a new submission supersedes it.
     */
    public function findTestingForGame(string $gameId): ?ApworldCandidate;

    /**
     * @return list<ApworldCandidate>
     */
    public function findAllTesting(): array;

    /**
     * The most recent candidate of the game, whatever its status: what the game page shows.
     */
    public function findLatestForGame(string $gameId): ?ApworldCandidate;

    /**
     * Whether a candidate of this game with this version tag was rejected: the nightly update never
     * retries it (story 38.6 AC 7).
     */
    public function hasRejectedVersion(string $gameId, string $versionTag): bool;

    public function flush(): void;
}
