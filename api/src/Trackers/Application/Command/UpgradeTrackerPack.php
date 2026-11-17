<?php

declare(strict_types=1);

namespace App\Trackers\Application\Command;

use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Exception\ValidationException;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use App\Trackers\Application\Port\TrackerPackArchiveInspectorInterface;
use App\Trackers\Application\Port\TrackerPackFetcherInterface;
use App\Trackers\Domain\Entity\TrackerPack;
use App\Trackers\Domain\Entity\TrackerPackVersion;
use App\Trackers\Domain\Repository\TrackerPackRepositoryInterface;
use App\Trackers\Domain\Service\TolerantJson;
use App\Trackers\Domain\Service\TrackerPackVersions;
use App\Trackers\Domain\ValueObject\TrackerPackManifest;
use App\Trackers\Domain\ValueObject\TrackerPackSource;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Installs a new version of a pack (story 54.3): the update the check found, or the version at a GitHub link pasted
 * by an admin (for repositories without proper releases). Every check of the import (54.1) applies, plus two: the
 * zip matches the announced sha256, and it is the same pack (`package_uid`). The new version is served at once, and
 * the pack keeps its {@see TrackerPackVersions::KEPT} latest versions; the zips of the others leave the object store
 * once the database no longer points at them.
 */
final readonly class UpgradeTrackerPack
{
    private const array MESSAGES = [
        'tracker_pack_url_invalid' => ImportTrackerPack::MESSAGES['tracker_pack_url_invalid'],
        'tracker_pack_manifest_invalid' => ImportTrackerPack::MESSAGES['tracker_pack_manifest_invalid'],
    ];

    public function __construct(
        private TrackerPackRepositoryInterface $packs,
        private TrackerPackFetcherInterface $fetcher,
        private TrackerPackArchiveInspectorInterface $inspector,
        private MinioStorageInterface $storage,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $minioApworldsBucket,
    ) {
    }

    /**
     * @param string|null $url null installs the update the check found
     *
     * @throws NotFoundException           tracker_pack_not_found
     * @throws ValidationException         tracker_pack_no_update, tracker_pack_checksum_mismatch, tracker_pack_uid_mismatch, and the import's refusals
     * @throws ConflictException           tracker_pack_version_already_served
     * @throws ServiceUnavailableException when GitHub or the object store cannot be reached
     */
    public function upgrade(string $packId, ?string $url, string $adminId): ServedTrackerPackVersion
    {
        $pack = $this->packs->find($packId);
        if (null === $pack) {
            throw new NotFoundException('Pack introuvable.', 'tracker_pack_not_found');
        }

        if (null === $url) {
            $update = $pack->getAvailableUpdate();
            if (null === $update) {
                throw new ValidationException('Aucune mise à jour connue pour ce pack : lance une vérification.', [], 'tracker_pack_no_update');
            }
            $archive = $this->fetcher->fetchZip($update->downloadUrl);
            $downloadUrl = $update->downloadUrl;
            $gitTag = $update->gitTag;
            $expectedSha = $update->sha256;
        } else {
            try {
                $source = TrackerPackSource::parse($url);
            } catch (\DomainException $e) {
                throw $this->failure($e->getMessage());
            }
            $fetched = $this->fetcher->fetch($source);
            $archive = $fetched->archive;
            $downloadUrl = $fetched->downloadUrl;
            $gitTag = $fetched->gitTag;
            $expectedSha = null;
        }

        return $this->install($pack, $archive, $downloadUrl, $gitTag, $expectedSha, $adminId);
    }

    private function install(TrackerPack $pack, string $archive, string $downloadUrl, ?string $gitTag, ?string $expectedSha, string $adminId): ServedTrackerPackVersion
    {
        $sha256 = hash('sha256', $archive);
        if (null !== $expectedSha && !hash_equals(strtolower($expectedSha), $sha256)) {
            $this->logger->warning('tracker_pack.checksum_mismatch', ['packId' => $pack->getId(), 'downloadUrl' => $downloadUrl]);
            throw new ValidationException('Le zip téléchargé ne correspond pas à l\'empreinte annoncée par le pack : mise à jour refusée.', [], 'tracker_pack_checksum_mismatch');
        }
        $versions = $this->packs->findVersions($pack->getId());
        $kept = array_find($versions, static fn (TrackerPackVersion $v): bool => $v->getSha256() === $sha256);
        if (null !== $kept && $kept->getId() === $pack->getCurrentVersionId()) {
            throw new ConflictException('Cette version est déjà celle proposée aux joueurs.', 'tracker_pack_version_already_served');
        }
        if (null !== $kept) {
            // The very zip of a version the pack kept: serve that one again rather than keep it twice.
            $variantReset = $pack->serve($kept);
            $this->packs->flush();
            $this->logger->info('tracker_pack.upgraded', ['packId' => $pack->getId(), 'adminId' => $adminId, 'packageVersion' => $kept->getPackageVersion(), 'reusedVersionId' => $kept->getId()]);

            return new ServedTrackerPackVersion($kept->getId(), $kept->getPackageVersion(), $variantReset);
        }

        $inspected = $this->inspector->inspect($archive);
        try {
            $manifest = TrackerPackManifest::fromArray(TolerantJson::decode($inspected->manifest));
        } catch (\DomainException $e) {
            throw $this->failure($e->getMessage());
        }
        if ($manifest->packageUid !== $pack->getPackageUid()) {
            throw new ValidationException(sprintf('Ce zip est un autre pack (« %s ») : importe-le à part plutôt que de remplacer celui-ci.', $manifest->packageUid), [], 'tracker_pack_uid_mismatch');
        }

        $now = $this->clock->now();
        $versionId = sprintf('%014x', (int) $now->format('Uu')).bin2hex(random_bytes(9));
        $storageKey = TrackerPackVersion::storageKeyFor($pack->getId(), $versionId);
        try {
            $this->storage->upload($this->minioApworldsBucket, $storageKey, $archive);
        } catch (\Throwable $e) {
            $this->logger->error('tracker_pack.storage_failed', ['packId' => $pack->getId(), 'key' => $storageKey, 'error' => $e->getMessage()]);
            throw new ServiceUnavailableException('Le stockage des fichiers ne répond pas, réessaie dans un instant.', 'tracker_pack_storage_failed');
        }

        $version = TrackerPackVersion::record($versionId, $pack->getId(), $manifest, $gitTag, $downloadUrl, $sha256, $storageKey, $inspected->rootPath, strlen($archive), $now);
        $variantReset = $pack->serve($version);
        $expired = TrackerPackVersions::expired([...$versions, $version], $versionId);
        $this->packs->save($pack, $version, $expired);

        // After the commit: the database no longer points at these zips.
        foreach ($expired as $old) {
            try {
                $this->storage->delete($this->minioApworldsBucket, $old->getStorageKey());
            } catch (\Throwable $e) {
                $this->logger->warning('tracker_pack.expired_delete_failed', ['packId' => $pack->getId(), 'key' => $old->getStorageKey(), 'error' => $e->getMessage()]);
            }
        }

        $this->logger->info('tracker_pack.upgraded', [
            'packId' => $pack->getId(),
            'adminId' => $adminId,
            'packageVersion' => $manifest->packageVersion,
            'downloadUrl' => $downloadUrl,
            'expired' => count($expired),
            'defaultVariantReset' => $variantReset,
        ]);

        return new ServedTrackerPackVersion($versionId, $manifest->packageVersion, $variantReset);
    }

    private function failure(string $code): ValidationException
    {
        $message = self::MESSAGES[$code] ?? 'Cette version du pack ne peut pas être installée.';

        return new ValidationException($message, ['url' => [$message]], $code);
    }
}
