<?php

declare(strict_types=1);

namespace App\Trackers\Domain\Entity;

use App\Trackers\Domain\ValueObject\TrackerPackManifest;
use Doctrine\ORM\Mapping as ORM;

/**
 * A PopTracker pack attached to a game (story 54.1): one pack per `package_uid` and game. Its versions are separate
 * immutable rows; the pack points at the one it serves.
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
}
