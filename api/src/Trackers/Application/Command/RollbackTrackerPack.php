<?php

declare(strict_types=1);

namespace App\Trackers\Application\Command;

use App\Shared\Application\Exception\NotFoundException;
use App\Trackers\Domain\Repository\TrackerPackRepositoryInterface;
use Psr\Log\LoggerInterface;

/** Serves again a version the pack kept (story 54.3), when the latest one breaks the tracker. */
final readonly class RollbackTrackerPack
{
    public function __construct(
        private TrackerPackRepositoryInterface $packs,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws NotFoundException tracker_pack_not_found, tracker_pack_version_not_found */
    public function rollback(string $packId, string $versionId, string $adminId): ServedTrackerPackVersion
    {
        $pack = $this->packs->find($packId);
        if (null === $pack) {
            throw new NotFoundException('Pack introuvable.', 'tracker_pack_not_found');
        }
        $version = $this->packs->findVersion($versionId);
        if (null === $version || $version->getPackId() !== $packId) {
            throw new NotFoundException('Cette version n\'est plus conservée pour ce pack.', 'tracker_pack_version_not_found');
        }

        $variantReset = $pack->serve($version);
        $this->packs->flush();
        $this->logger->info('tracker_pack.rolled_back', ['packId' => $packId, 'versionId' => $versionId, 'adminId' => $adminId]);

        return new ServedTrackerPackVersion($versionId, $version->getPackageVersion(), $variantReset);
    }
}
