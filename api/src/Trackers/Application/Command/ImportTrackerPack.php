<?php

declare(strict_types=1);

namespace App\Trackers\Application\Command;

use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Exception\ValidationException;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use App\Trackers\Application\Port\TrackerGameInterface;
use App\Trackers\Application\Port\TrackerPackArchiveInspectorInterface;
use App\Trackers\Application\Port\TrackerPackFetcherInterface;
use App\Trackers\Domain\Entity\TrackerPack;
use App\Trackers\Domain\Entity\TrackerPackVersion;
use App\Trackers\Domain\Repository\TrackerPackRepositoryInterface;
use App\Trackers\Domain\Service\TolerantJson;
use App\Trackers\Domain\ValueObject\TrackerPackManifest;
use App\Trackers\Domain\ValueObject\TrackerPackSource;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Imports a PopTracker pack onto a game from a GitHub link (story 54.1): download, checks, then the zip goes to the
 * object store as downloaded and the pack is saved with its first version. Nothing of the pack runs on the server:
 * only its manifest is read.
 */
final readonly class ImportTrackerPack
{
    public const array MESSAGES = [
        'tracker_pack_url_invalid' => 'Ce lien n\'est pas un lien GitHub de pack : colle le lien d\'une release, du dépôt ou d\'un zip.',
        'tracker_pack_manifest_invalid' => 'Le manifest.json du pack est illisible ou incomplet (package_uid et name sont obligatoires).',
    ];

    public function __construct(
        private TrackerGameInterface $games,
        private TrackerPackFetcherInterface $fetcher,
        private TrackerPackArchiveInspectorInterface $inspector,
        private TrackerPackRepositoryInterface $packs,
        private MinioStorageInterface $storage,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $minioApworldsBucket,
    ) {
    }

    /**
     * @throws NotFoundException           game_not_found
     * @throws ValidationException         when the link, the download or the zip is refused
     * @throws ConflictException           tracker_pack_already_imported
     * @throws ServiceUnavailableException when GitHub or the object store cannot be reached
     */
    public function import(string $gameId, string $url, string $adminId): ImportedTrackerPack
    {
        if (!$this->games->exists($gameId)) {
            throw new NotFoundException('Jeu introuvable.', 'game_not_found');
        }
        try {
            $source = TrackerPackSource::parse($url);
        } catch (\DomainException $e) {
            throw $this->failure($e);
        }

        $fetched = $this->fetcher->fetch($source);
        $inspected = $this->inspector->inspect($fetched->archive);
        try {
            $manifest = TrackerPackManifest::fromArray(TolerantJson::decode($inspected->manifest));
        } catch (\DomainException $e) {
            throw $this->failure($e);
        }

        if (null !== $this->packs->findByGameAndPackageUid($gameId, $manifest->packageUid)) {
            throw new ConflictException(sprintf('Le pack « %s » est déjà importé sur ce jeu : mets-le à jour plutôt que de le réimporter.', $manifest->name), 'tracker_pack_already_imported');
        }

        $now = $this->clock->now();
        $packId = self::newId($now);
        $versionId = self::newId($now);
        $storageKey = TrackerPackVersion::storageKeyFor($packId, $versionId);
        try {
            $this->storage->upload($this->minioApworldsBucket, $storageKey, $fetched->archive);
        } catch (\Throwable $e) {
            $this->logger->error('tracker_pack.storage_failed', ['gameId' => $gameId, 'key' => $storageKey, 'error' => $e->getMessage()]);
            throw new ServiceUnavailableException('Le stockage des fichiers ne répond pas, réessaie dans un instant.', 'tracker_pack_storage_failed');
        }

        $version = TrackerPackVersion::record(
            $versionId,
            $packId,
            $manifest,
            $fetched->gitTag,
            $fetched->downloadUrl,
            hash('sha256', $fetched->archive),
            $storageKey,
            $inspected->rootPath,
            strlen($fetched->archive),
            $now,
        );
        $pack = TrackerPack::import($packId, $gameId, $source->repositoryUrl(), $manifest, $fetched->licenseSpdx, $versionId, $adminId, $now);
        $this->packs->save($pack, $version);

        $this->logger->info('tracker_pack.imported', [
            'gameId' => $gameId,
            'packId' => $packId,
            'packageUid' => $manifest->packageUid,
            'packageVersion' => $manifest->packageVersion,
            'downloadUrl' => $fetched->downloadUrl,
        ]);

        return new ImportedTrackerPack($packId, $manifest->name, $manifest->packageVersion, $manifest->hasArchipelagoVariant());
    }

    private function failure(\DomainException $e): ValidationException
    {
        $code = $e->getMessage();
        $message = self::MESSAGES[$code] ?? 'Ce pack ne peut pas être importé.';

        return new ValidationException($message, ['url' => [$message]], $code);
    }

    private static function newId(\DateTimeImmutable $now): string
    {
        return sprintf('%014x', (int) $now->format('Uu')).bin2hex(random_bytes(9));
    }
}
