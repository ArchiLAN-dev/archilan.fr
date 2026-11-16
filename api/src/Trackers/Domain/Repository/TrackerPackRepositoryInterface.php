<?php

declare(strict_types=1);

namespace App\Trackers\Domain\Repository;

use App\Trackers\Domain\Entity\TrackerPack;
use App\Trackers\Domain\Entity\TrackerPackVersion;

interface TrackerPackRepositoryInterface
{
    public function findByGameAndPackageUid(string $gameId, string $packageUid): ?TrackerPack;

    /** Saves a pack with one of its versions, in one transaction. */
    public function save(TrackerPack $pack, TrackerPackVersion $version): void;
}
