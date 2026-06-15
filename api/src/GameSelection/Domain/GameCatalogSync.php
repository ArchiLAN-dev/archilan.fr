<?php

declare(strict_types=1);

namespace App\GameSelection\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class GameCatalogSync
{
    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne(inversedBy: 'catalogSync')]
        #[ORM\JoinColumn(name: 'game_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
        private Game $game,
        #[ORM\Column(name: 'catalog_sheet_name', type: 'string', length: 160, nullable: true)]
        private ?string $catalogSheetName = null,
        #[ORM\Column(name: 'apworld_source_url', type: 'string', length: 500, nullable: true)]
        private ?string $apworldSourceUrl = null,
        #[ORM\Column(name: 'apworld_deployed_version', type: 'string', length: 50, nullable: true)]
        private ?string $apworldDeployedVersion = null,
        #[ORM\Column(name: 'apworld_latest_version', type: 'string', length: 50, nullable: true)]
        private ?string $apworldLatestVersion = null,
        #[ORM\Column(name: 'apworld_checked_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $apworldCheckedAt = null,
        #[ORM\Column(name: 'apworld_release_url', type: 'string', length: 500, nullable: true)]
        private ?string $apworldReleaseUrl = null,
        #[ORM\Column(name: 'igdb_id', type: 'integer', nullable: true)]
        private ?int $igdbId = null,
        #[ORM\Column(name: 'adult_content', type: 'boolean', options: ['default' => false])]
        private bool $adultContent = false,
        #[ORM\Column(name: 'bundled_with_ap', type: 'boolean', options: ['default' => false])]
        private bool $bundledWithAp = false,
        #[ORM\Column(name: 'steam_app_id', type: 'integer', nullable: true)]
        private ?int $steamAppId = null,
        /**
         * Raw IGDB platforms for the game, as resolved from the IGDB `games` endpoint.
         *
         * @var list<array{id: int, name: string}>|null
         */
        #[ORM\Column(name: 'platforms', type: 'json', nullable: true)]
        private ?array $platforms = null,
    ) {
        $game->setCatalogSync($this);
    }

    public function update(
        ?string $catalogSheetName,
        ?string $apworldSourceUrl,
        ?string $apworldDeployedVersion,
        ?int $igdbId,
    ): void {
        $this->catalogSheetName = $catalogSheetName;
        $this->apworldSourceUrl = $apworldSourceUrl;
        $this->apworldDeployedVersion = null !== $apworldDeployedVersion ? ltrim($apworldDeployedVersion, 'vV') : null;
        $this->igdbId = $igdbId;
    }

    public function recordApworldCheck(string $latestVersion, \DateTimeImmutable $checkedAt, ?string $releaseUrl = null): void
    {
        $this->apworldLatestVersion = ltrim($latestVersion, 'vV');
        $this->apworldCheckedAt = $checkedAt;
        $this->apworldReleaseUrl = $releaseUrl;
    }

    public function setApworldDeployedVersion(?string $version): void
    {
        $this->apworldDeployedVersion = null !== $version ? ltrim($version, 'vV') : null;
    }

    public function computeApworldUpdateStatus(): string
    {
        if (null === $this->apworldSourceUrl || '' === $this->apworldSourceUrl) {
            return Game::UPDATE_STATUS_NOT_TRACKED;
        }

        if (!str_starts_with($this->apworldSourceUrl, 'https://github.com/')) {
            return Game::UPDATE_STATUS_NOT_TRACKED;
        }

        if (null === $this->apworldCheckedAt || null === $this->apworldLatestVersion) {
            return Game::UPDATE_STATUS_UNKNOWN;
        }

        if (null === $this->apworldDeployedVersion) {
            return Game::UPDATE_STATUS_UNKNOWN;
        }

        $latest = ltrim($this->apworldLatestVersion, 'vV');
        $deployed = ltrim($this->apworldDeployedVersion, 'vV');

        return $latest === $deployed
            ? Game::UPDATE_STATUS_UP_TO_DATE
            : Game::UPDATE_STATUS_UPDATE_AVAILABLE;
    }

    public function getGame(): Game
    {
        return $this->game;
    }

    public function getCatalogSheetName(): ?string
    {
        return $this->catalogSheetName;
    }

    public function getApworldSourceUrl(): ?string
    {
        return $this->apworldSourceUrl;
    }

    public function getApworldDeployedVersion(): ?string
    {
        return $this->apworldDeployedVersion;
    }

    public function getApworldLatestVersion(): ?string
    {
        return $this->apworldLatestVersion;
    }

    public function getApworldCheckedAt(): ?\DateTimeImmutable
    {
        return $this->apworldCheckedAt;
    }

    public function getApworldReleaseUrl(): ?string
    {
        return $this->apworldReleaseUrl;
    }

    public function getIgdbId(): ?int
    {
        return $this->igdbId;
    }

    public function recordSteamAppId(?int $steamAppId): void
    {
        $this->steamAppId = $steamAppId;
    }

    public function getSteamAppId(): ?int
    {
        return $this->steamAppId;
    }

    /**
     * @param list<array{id: int, name: string}>|null $platforms
     */
    public function recordPlatforms(?array $platforms): void
    {
        $this->platforms = null === $platforms || [] === $platforms ? null : $platforms;
    }

    /**
     * @return list<array{id: int, name: string}>|null
     */
    public function getPlatforms(): ?array
    {
        return $this->platforms;
    }

    public function isAdultContent(): bool
    {
        return $this->adultContent;
    }

    public function isBundledWithAp(): bool
    {
        return $this->bundledWithAp;
    }
}
