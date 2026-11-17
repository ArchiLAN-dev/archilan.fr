<?php

declare(strict_types=1);

namespace App\Trackers\Domain\Repository;

use App\Trackers\Domain\Entity\TrackerPack;
use App\Trackers\Domain\Entity\TrackerPackCompatibility;
use App\Trackers\Domain\Entity\TrackerPackVersion;

interface TrackerPackRepositoryInterface
{
    public function find(string $id): ?TrackerPack;

    /** @return list<TrackerPack> */
    public function findByGame(string $gameId): array;

    public function findByGameAndPackageUid(string $gameId, string $packageUid): ?TrackerPack;

    /** @return list<TrackerPack> the packs offered to players, on every game */
    public function findEnabled(): array;

    public function findVersion(string $versionId): ?TrackerPackVersion;

    /** @return list<TrackerPackVersion> every version kept for the pack */
    public function findVersions(string $packId): array;

    /** Story 54.8: the last try of one variant of a version. */
    public function findCompatibility(string $versionId, string $variantUid): ?TrackerPackCompatibility;

    /** Story 54.8: saves a try, new or replacing the previous one. */
    public function saveCompatibility(TrackerPackCompatibility $compatibility): void;

    /**
     * Saves a pack with one of its versions, and removes the versions it no longer keeps (and their tries), in one
     * transaction.
     *
     * @param list<TrackerPackVersion> $expired
     */
    public function save(TrackerPack $pack, TrackerPackVersion $version, array $expired = []): void;

    /** Saves changes made to packs already stored, in one transaction. */
    public function flush(): void;
}
