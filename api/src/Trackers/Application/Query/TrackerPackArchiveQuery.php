<?php

declare(strict_types=1);

namespace App\Trackers\Application\Query;

use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use App\Trackers\Domain\Entity\TrackerPack;
use App\Trackers\Domain\Entity\TrackerPackVersion;
use App\Trackers\Domain\Repository\TrackerPackRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The zip of a pack version for the tracker (story 54.2). Players only reach the versions of enabled packs; an admin
 * reaches every version, to try a pack before offering it (story 54.8).
 *
 * Served through the API rather than a presigned object store link: the tracker reads the zip with `fetch`, and the
 * API already answers the site's origin, where the object store's CORS rules are not ours to rely on.
 */
final readonly class TrackerPackArchiveQuery
{
    public function __construct(
        private TrackerPackRepositoryInterface $packs,
        private MinioStorageInterface $storage,
        private LoggerInterface $logger,
        private string $minioApworldsBucket,
    ) {
    }

    /**
     * @return TrackerPackArchive|null null when the version does not exist or is not served to this caller
     *
     * @throws ServiceUnavailableException when the object store cannot be read
     */
    /**
     * Story 54.13: the fingerprint of a zip the caller may download, without reading the object store, so a browser
     * that already holds it gets a 304. Null when the version is not theirs to download.
     */
    public function fingerprint(string $versionId, bool $asAdmin): ?string
    {
        return $this->reachable($versionId, $asAdmin)?->getSha256();
    }

    public function forDownload(string $versionId, bool $asAdmin): ?TrackerPackArchive
    {
        $version = $this->reachable($versionId, $asAdmin);
        $pack = null === $version ? null : $this->packs->find($version->getPackId());
        if (null === $version || null === $pack) {
            return null;
        }

        try {
            $contents = $this->storage->download($this->minioApworldsBucket, $version->getStorageKey());
        } catch (\Throwable $e) {
            $this->logger->error('tracker_pack.archive_read_failed', ['versionId' => $versionId, 'error' => $e->getMessage()]);
            throw new ServiceUnavailableException('Le pack est momentanément indisponible, réessaie dans un instant.', 'tracker_pack_storage_failed');
        }

        $name = sprintf('%s-%s', $pack->getPackageUid(), $version->getPackageVersion() ?? $version->getId());
        $slug = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? '', '-.');

        return new TrackerPackArchive($contents, $version->getSha256(), ('' === $slug ? 'pack' : $slug).'.zip');
    }

    private function reachable(string $versionId, bool $asAdmin): ?TrackerPackVersion
    {
        $version = $this->packs->findVersion($versionId);
        $pack = null === $version ? null : $this->packs->find($version->getPackId());
        if (null === $version || null === $pack || (!$asAdmin && !$this->offeredToPlayers($pack, $version))) {
            return null;
        }

        return $version;
    }

    /**
     * A player reaches the version an enabled pack serves, and the older ones (a page rendered before a serve may
     * still ask for one). Story 54.13: never a newer version kept for an admin's try.
     */
    private function offeredToPlayers(TrackerPack $pack, TrackerPackVersion $version): bool
    {
        if (!$pack->isEnabled()) {
            return false;
        }
        if ($version->getId() === $pack->getCurrentVersionId()) {
            return true;
        }
        $current = $this->packs->findVersion($pack->getCurrentVersionId());

        return null !== $current && $version->getImportedAt() < $current->getImportedAt();
    }
}
