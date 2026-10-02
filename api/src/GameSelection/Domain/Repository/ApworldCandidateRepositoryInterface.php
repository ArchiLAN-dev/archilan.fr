<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Repository;

use App\GameSelection\Domain\Entity\ApworldCandidate;

interface ApworldCandidateRepositoryInterface
{
    public function save(ApworldCandidate $candidate): void;

    public function findById(string $id): ?ApworldCandidate;

    /**
     * The pending candidate of this game, in test or awaiting the admin's approval (story 38.14), if any. There is
     * at most one: a new submission supersedes it, and an automatic update leaves the game alone meanwhile.
     */
    public function findPendingForGame(string $gameId): ?ApworldCandidate;

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
