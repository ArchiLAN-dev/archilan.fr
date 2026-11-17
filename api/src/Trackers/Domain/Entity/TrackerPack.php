<?php

declare(strict_types=1);

namespace App\Trackers\Domain\Entity;

use App\Trackers\Domain\Service\TrackerPackVersions;
use App\Trackers\Domain\ValueObject\TrackerPackManifest;
use App\Trackers\Domain\ValueObject\TrackerPackUpdate;
use Doctrine\ORM\Mapping as ORM;

/**
 * A PopTracker pack attached to a game (story 54.1): one pack per `package_uid` and game. Its versions are separate
 * immutable rows; the pack points at the one it serves.
 *
 * Story 54.2: a pack is offered to players once enabled. A pack without a detected licence can only be enabled once
 * an admin vouched that it may be shared. Which pack of a game opens by default is settled across the game's packs
 * by {@see \App\Trackers\Domain\Service\TrackerPackShelf}.
 *
 * Story 54.3: the nightly check keeps the newer version it found, if any, until an admin applies it. Serving another
 * version (an update, or a way back) only moves `currentVersionId`: versions themselves never change.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tracker_pack')]
#[ORM\UniqueConstraint(name: 'uniq_tracker_pack_game_uid', columns: ['game_id', 'package_uid'])]
final class TrackerPack
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'game_id', type: 'string', length: 32)]
        private string $gameId,
        #[ORM\Column(name: 'source_url', type: 'text')]
        private string $sourceUrl,
        #[ORM\Column(name: 'package_uid', type: 'string', length: 120)]
        private string $packageUid,
        #[ORM\Column(type: 'string', length: 200)]
        private string $name,
        #[ORM\Column(type: 'string', length: 200, nullable: true)]
        private ?string $author,
        #[ORM\Column(name: 'game_name', type: 'string', length: 200, nullable: true)]
        private ?string $gameName,
        #[ORM\Column(name: 'license_spdx', type: 'string', length: 64, nullable: true)]
        private ?string $licenseSpdx,
        #[ORM\Column(name: 'versions_url', type: 'text', nullable: true)]
        private ?string $versionsUrl,
        #[ORM\Column(name: 'current_version_id', type: 'string', length: 32)]
        private string $currentVersionId,
        #[ORM\Column(name: 'created_by', type: 'string', length: 32)]
        private string $createdBy,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(type: 'boolean', options: ['default' => false])]
        private bool $enabled = false,
        #[ORM\Column(name: 'is_default', type: 'boolean', options: ['default' => false])]
        private bool $isDefault = false,
        #[ORM\Column(name: 'default_variant_uid', type: 'string', length: 120, nullable: true)]
        private ?string $defaultVariantUid = null,
        #[ORM\Column(name: 'license_acknowledged', type: 'boolean', options: ['default' => false])]
        private bool $licenseAcknowledged = false,
        /** @var array<mixed>|null {@see TrackerPackUpdate::toArray()} */
        #[ORM\Column(name: 'available_update', type: 'json', nullable: true)]
        private ?array $availableUpdate = null,
        #[ORM\Column(name: 'last_checked_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $lastCheckedAt = null,
        #[ORM\Column(name: 'last_check_error', type: 'string', length: 64, nullable: true)]
        private ?string $lastCheckError = null,
    ) {
    }

    public static function import(
        string $id,
        string $gameId,
        string $sourceUrl,
        TrackerPackManifest $manifest,
        ?string $licenseSpdx,
        string $versionId,
        string $createdBy,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            $id,
            $gameId,
            $sourceUrl,
            $manifest->packageUid,
            $manifest->name,
            $manifest->author,
            $manifest->gameName,
            $licenseSpdx,
            $manifest->versionsUrl,
            $versionId,
            $createdBy,
            $now,
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getGameId(): string
    {
        return $this->gameId;
    }

    public function getPackageUid(): string
    {
        return $this->packageUid;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCurrentVersionId(): string
    {
        return $this->currentVersionId;
    }

    public function getLicenseSpdx(): ?string
    {
        return $this->licenseSpdx;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function getDefaultVariantUid(): ?string
    {
        return $this->defaultVariantUid;
    }

    public function isLicenseAcknowledged(): bool
    {
        return $this->licenseAcknowledged;
    }

    /** A detected licence, or an admin who vouched for the pack. */
    public function maySharePack(): bool
    {
        return null !== $this->licenseSpdx || $this->licenseAcknowledged;
    }

    /** @throws \DomainException tracker_pack_license_unacknowledged when withdrawn from an enabled pack without licence */
    public function acknowledgeLicense(bool $acknowledged): void
    {
        if (!$acknowledged && $this->enabled && null === $this->licenseSpdx) {
            throw new \DomainException('tracker_pack_license_unacknowledged');
        }
        $this->licenseAcknowledged = $acknowledged;
    }

    /**
     * Null goes back to the first variant of the manifest.
     *
     * @param list<string> $knownUids the variants of the version the pack serves
     *
     * @throws \DomainException tracker_pack_variant_unknown
     */
    public function chooseDefaultVariant(?string $uid, array $knownUids): void
    {
        if (null !== $uid && !in_array($uid, $knownUids, true)) {
            throw new \DomainException('tracker_pack_variant_unknown');
        }
        $this->defaultVariantUid = $uid;
    }

    /** Only through {@see \App\Trackers\Domain\Service\TrackerPackShelf}, which keeps one default per game. */
    public function markEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /** Only through {@see \App\Trackers\Domain\Service\TrackerPackShelf}, which keeps one default per game. */
    public function markDefault(bool $isDefault): void
    {
        $this->isDefault = $isDefault;
    }

    public function getSourceUrl(): string
    {
        return $this->sourceUrl;
    }

    public function getVersionsUrl(): ?string
    {
        return $this->versionsUrl;
    }

    public function getAvailableUpdate(): ?TrackerPackUpdate
    {
        return null === $this->availableUpdate ? null : TrackerPackUpdate::fromArray($this->availableUpdate);
    }

    /** A check that went through: the newer version found, or none. */
    public function recordCheck(?TrackerPackUpdate $update, \DateTimeImmutable $at): void
    {
        $this->availableUpdate = $update?->toArray();
        $this->lastCheckedAt = $at;
        $this->lastCheckError = null;
    }

    /** A check that failed: what was found before stays offered. */
    public function recordCheckFailure(string $code, \DateTimeImmutable $at): void
    {
        $this->lastCheckedAt = $at;
        $this->lastCheckError = mb_substr($code, 0, 64);
    }

    /**
     * Serves another version of this pack, newer or older. An offered update at or below it is dropped. A default
     * variant the version no longer has goes back to the first one.
     *
     * @return bool whether the default variant was reset
     *
     * @throws \DomainException tracker_pack_version_not_found when the version is not one of this pack
     */
    public function serve(TrackerPackVersion $version): bool
    {
        if ($version->getPackId() !== $this->id) {
            throw new \DomainException('tracker_pack_version_not_found');
        }
        $this->currentVersionId = $version->getId();

        $update = $this->getAvailableUpdate();
        $served = $version->getPackageVersion();
        if (null !== $update && null !== $served && TrackerPackVersions::compare($update->packageVersion, $served) <= 0) {
            $this->availableUpdate = null;
        }

        $uids = array_column($version->getVariants(), 'uid');
        if (null !== $this->defaultVariantUid && !in_array($this->defaultVariantUid, $uids, true)) {
            $this->defaultVariantUid = null;

            return true;
        }

        return false;
    }
}
