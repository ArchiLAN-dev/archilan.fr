<?php

declare(strict_types=1);

namespace App\Trackers\Domain\Entity;

use App\Trackers\Domain\ValueObject\TrackerPackManifest;
use Doctrine\ORM\Mapping as ORM;

/**
 * One imported version of a PopTracker pack (story 54.1). Immutable: the zip is stored as downloaded, so its sha256
 * stays comparable with the pack's own `versions.json`. `rootPath` is the folder holding `manifest.json` inside the
 * zip (empty when it sits at the root, `{repo}-{version}` for a tag's source archive).
 */
#[ORM\Entity]
#[ORM\Table(name: 'tracker_pack_version')]
#[ORM\Index(name: 'idx_tracker_pack_version_pack', columns: ['pack_id'])]
final class TrackerPackVersion
{
    /** Largest zip the site downloads. */
    public const int MAX_ARCHIVE_BYTES = 50 * 1024 * 1024;

    /** Largest total the zip may declare once unpacked. */
    public const int MAX_UNPACKED_BYTES = 200 * 1024 * 1024;

    public const int MAX_FILES = 5000;

    /** @param list<array{uid: string, displayName: string, flags: list<string>}> $variants */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'pack_id', type: 'string', length: 32)]
        private string $packId,
        #[ORM\Column(name: 'package_version', type: 'string', length: 64, nullable: true)]
        private ?string $packageVersion,
        #[ORM\Column(name: 'git_tag', type: 'string', length: 120, nullable: true)]
        private ?string $gitTag,
        #[ORM\Column(name: 'download_url', type: 'text')]
        private string $downloadUrl,
        #[ORM\Column(type: 'string', length: 64)]
        private string $sha256,
        #[ORM\Column(name: 'storage_key', type: 'string', length: 255)]
        private string $storageKey,
        #[ORM\Column(name: 'root_path', type: 'string', length: 255)]
        private string $rootPath,
        #[ORM\Column(name: 'size_bytes', type: 'integer')]
        private int $sizeBytes,
        #[ORM\Column(type: 'json')]
        private array $variants,
        #[ORM\Column(name: 'min_poptracker_version', type: 'string', length: 64, nullable: true)]
        private ?string $minPopTrackerVersion,
        #[ORM\Column(name: 'target_poptracker_version', type: 'string', length: 64, nullable: true)]
        private ?string $targetPopTrackerVersion,
        #[ORM\Column(name: 'imported_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $importedAt,
    ) {
    }

    public static function record(
        string $id,
        string $packId,
        TrackerPackManifest $manifest,
        ?string $gitTag,
        string $downloadUrl,
        string $sha256,
        string $storageKey,
        string $rootPath,
        int $sizeBytes,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            $id,
            $packId,
            $manifest->packageVersion,
            $gitTag,
            $downloadUrl,
            $sha256,
            $storageKey,
            $rootPath,
            $sizeBytes,
            $manifest->variantsAsArray(),
            $manifest->minPopTrackerVersion,
            $manifest->targetPopTrackerVersion,
            $now,
        );
    }

    /** Where a version's zip is kept in the object store. */
    public static function storageKeyFor(string $packId, string $versionId): string
    {
        return sprintf('tracker-packs/%s/%s.zip', $packId, $versionId);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPackId(): string
    {
        return $this->packId;
    }

    public function getPackageVersion(): ?string
    {
        return $this->packageVersion;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    /** @return list<array{uid: string, displayName: string, flags: list<string>}> */
    public function getVariants(): array
    {
        return $this->variants;
    }
}
