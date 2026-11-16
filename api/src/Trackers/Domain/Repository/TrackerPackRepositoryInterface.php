<?php

declare(strict_types=1);

namespace App\Trackers\Domain\Repository;

use App\Trackers\Domain\Entity\TrackerPack;
use App\Trackers\Domain\Entity\TrackerPackVersion;

interface TrackerPackRepositoryInterface
{
    public function find(string $id): ?TrackerPack;

    /** @return list<TrackerPack> */
    public function findByGame(string $gameId): array;

    public function findByGameAndPackageUid(string $gameId, string $packageUid): ?TrackerPack;

    public function findVersion(string $versionId): ?TrackerPackVersion;

    /** Saves a pack with one of its versions, in one transaction. */
    public function save(TrackerPack $pack, TrackerPackVersion $version): void;

    /** Saves changes made to packs already stored, in one transaction. */
    public function flush(): void;
}
