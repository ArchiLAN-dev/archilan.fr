<?php

declare(strict_types=1);

namespace App\GameSelection\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_games_slug', columns: ['slug'])]
class Game
{
    public const AVAILABILITY_AVAILABLE = 'available';
    public const AVAILABILITY_UNAVAILABLE = 'unavailable';
    public const AVAILABILITY_EXPERIMENTAL = 'experimental';

    public const UPDATE_STATUS_NOT_TRACKED = 'not_tracked';
    public const UPDATE_STATUS_UNKNOWN = 'unknown';
    public const UPDATE_STATUS_UP_TO_DATE = 'up_to_date';
    public const UPDATE_STATUS_UPDATE_AVAILABLE = 'update_available';

    #[ORM\OneToOne(mappedBy: 'game', cascade: ['persist', 'remove'])]
    private ?GameCatalogSync $catalogSync = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(type: 'string', length: 120)]
        private string $name,
        #[ORM\Column(type: 'string', length: 120)]
        private string $slug,
        #[ORM\Column(type: 'text')]
        private string $description,
        #[ORM\Column(name: 'cover_image_url', type: 'text', nullable: true)]
        private ?string $coverImageUrl,
        #[ORM\Column(name: 'cover_image_alt', type: 'string', length: 160)]
        private string $coverImageAlt,
        #[ORM\Column(name: 'cover_image_credit', type: 'string', length: 160)]
        private string $coverImageCredit,
        #[ORM\Column(type: 'string', length: 32)]
        private string $availability,
        #[ORM\Column(name: 'archipelago_game_name', type: 'string', length: 120, nullable: true)]
        private ?string $archipelagoGameName,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(name: 'apworld_storage_key', type: 'string', length: 500, nullable: true)]
        private ?string $apworldStorageKey = null,
        #[ORM\Column(name: 'apworld_hash', type: 'string', length: 64, nullable: true)]
        private ?string $apworldHash = null,
        #[ORM\Column(name: 'apworld_uploaded_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $apworldUploadedAt = null,
        #[ORM\Column(name: 'default_yaml', type: 'text', nullable: true)]
        private ?string $defaultYaml = null,
        #[ORM\Column(name: 'apworld_minio_key', type: 'string', length: 500, nullable: true)]
        private ?string $apworldMinioKey = null,
        #[ORM\Column(name: 'availability_locked', type: 'boolean', options: ['default' => false])]
        private bool $availabilityLocked = false,
    ) {
    }

    public static function create(
        string $name,
        string $slug,
        string $description,
        ?string $coverImageUrl,
        string $coverImageAlt,
        string $coverImageCredit,
        string $availability,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            bin2hex(random_bytes(16)),
            trim($name),
            self::normalizeSlug($slug),
            trim($description),
            null !== $coverImageUrl ? trim($coverImageUrl) : null,
            trim($coverImageAlt),
            trim($coverImageCredit),
            $availability,
            null,
            $now,
            $now,
        );
    }

    public function update(
        string $name,
        string $slug,
        string $description,
        ?string $coverImageUrl,
        string $coverImageAlt,
        string $coverImageCredit,
        string $availability,
        \DateTimeImmutable $now,
    ): void {
        $this->name = trim($name);
        $this->slug = self::normalizeSlug($slug);
        $this->description = trim($description);
        $this->coverImageUrl = null !== $coverImageUrl ? trim($coverImageUrl) : null;
        $this->coverImageAlt = trim($coverImageAlt);
        $this->coverImageCredit = trim($coverImageCredit);
        $this->availability = $availability;
        $this->updatedAt = $now;
    }

    public function configureApworld(string $storageKey, string $hash, string $archipelagoGameName, string $defaultYaml, \DateTimeImmutable $now): void
    {
        $this->apworldStorageKey = $storageKey;
        $this->apworldHash = $hash;
        $this->apworldUploadedAt = $now;
        $this->defaultYaml = $defaultYaml;
        $this->archipelagoGameName = $archipelagoGameName;
        $this->updatedAt = $now;
    }

    public function setApworldMinioKey(string $key): void
    {
        $this->apworldMinioKey = $key;
    }

    public function isApworldReady(): bool
    {
        return null !== $this->apworldStorageKey;
    }

    public function isYamlReady(): bool
    {
        return null !== $this->archipelagoGameName && '' !== $this->archipelagoGameName;
    }

    /**
     * @return list<string>
     */
    public static function supportedAvailabilities(): array
    {
        return [
            self::AVAILABILITY_AVAILABLE,
            self::AVAILABILITY_UNAVAILABLE,
            self::AVAILABILITY_EXPERIMENTAL,
        ];
    }

    public static function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCoverImageUrl(): ?string
    {
        return $this->coverImageUrl;
    }

    public function getCoverImageAlt(): string
    {
        return $this->coverImageAlt;
    }

    public function getCoverImageCredit(): string
    {
        return $this->coverImageCredit;
    }

    public function getAvailability(): string
    {
        return $this->availability;
    }

    public function getArchipelagoGameName(): ?string
    {
        return $this->archipelagoGameName;
    }

    public function getApworldStorageKey(): ?string
    {
        return $this->apworldStorageKey;
    }

    public function getApworldMinioKey(): ?string
    {
        return $this->apworldMinioKey;
    }

    public function getApworldHash(): ?string
    {
        return $this->apworldHash;
    }

    public function getApworldUploadedAt(): ?\DateTimeImmutable
    {
        return $this->apworldUploadedAt;
    }

    public function getDefaultYaml(): ?string
    {
        return $this->defaultYaml;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isAvailabilityLocked(): bool
    {
        return $this->availabilityLocked;
    }

    public function setAvailabilityLocked(bool $locked): void
    {
        $this->availabilityLocked = $locked;
    }

    public function updateCatalogueMetadata(
        ?string $catalogSheetName = null,
        ?string $sourceUrl = null,
        ?string $deployedVersion = null,
        bool $availabilityLocked = false,
    ): void {
        $this->availabilityLocked = $availabilityLocked;

        if (null === $this->catalogSync) {
            new GameCatalogSync($this, $catalogSheetName, $sourceUrl, $deployedVersion);
        } else {
            $this->catalogSync->update(
                $catalogSheetName ?? $this->catalogSync->getCatalogSheetName(),
                $sourceUrl ?? $this->catalogSync->getApworldSourceUrl(),
                $deployedVersion ?? $this->catalogSync->getApworldDeployedVersion(),
                $this->catalogSync->getIgdbId(),
            );
        }
    }

    public static function normalizeApworldSourceUrl(string $url): ?string
    {
        if ('' === $url) {
            return null;
        }

        $parsed = parse_url($url);
        if (false === $parsed || 'https' !== ($parsed['scheme'] ?? '') || 'github.com' !== ($parsed['host'] ?? '')) {
            return null;
        }

        $path = rtrim($parsed['path'] ?? '/', '/');
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';

        $parts = array_values(array_filter(
            explode('/', $path),
            static fn (string $s): bool => '' !== $s,
        ));

        if (count($parts) < 2) {
            return null;
        }

        $subParts = array_slice($parts, 2);

        if (0 === count($subParts)) {
            // /owner/repo - valid
        } elseif ('releases' === $subParts[0]) {
            if (1 === count($subParts)) {
                // /releases - valid
            } elseif ('latest' === $subParts[1] && 2 === count($subParts)) {
                // /releases/latest - valid
            } elseif ('tag' === $subParts[1] && 3 === count($subParts)) {
                // /releases/tag/{version} - valid
            } else {
                return null;
            }
        } elseif ('tree' === $subParts[0] && count($subParts) >= 2) {
            // /tree/{branch} - valid
        } else {
            return null;
        }

        return 'https://github.com'.$path.$query;
    }

    public function setCatalogSync(GameCatalogSync $sync): void
    {
        $this->catalogSync = $sync;
    }

    public function getCatalogSync(): ?GameCatalogSync
    {
        return $this->catalogSync;
    }

    public function getCatalogSheetName(): ?string
    {
        return $this->catalogSync?->getCatalogSheetName();
    }

    public function getApworldSourceUrl(): ?string
    {
        return $this->catalogSync?->getApworldSourceUrl();
    }

    public function recordApworldCheck(string $latestVersion, \DateTimeImmutable $checkedAt, ?string $releaseUrl = null): void
    {
        $this->catalogSync?->recordApworldCheck($latestVersion, $checkedAt, $releaseUrl);
    }

    public function computeApworldUpdateStatus(): string
    {
        return $this->catalogSync?->computeApworldUpdateStatus() ?? self::UPDATE_STATUS_NOT_TRACKED;
    }

    public function getApworldDeployedVersion(): ?string
    {
        return $this->catalogSync?->getApworldDeployedVersion();
    }

    public function getApworldLatestVersion(): ?string
    {
        return $this->catalogSync?->getApworldLatestVersion();
    }

    public function getApworldReleaseUrl(): ?string
    {
        return $this->catalogSync?->getApworldReleaseUrl();
    }

    public function getApworldCheckedAt(): ?\DateTimeImmutable
    {
        return $this->catalogSync?->getApworldCheckedAt();
    }

    public function getIgdbId(): ?int
    {
        return $this->catalogSync?->getIgdbId();
    }

    public function isAdultContent(): bool
    {
        return $this->catalogSync?->isAdultContent() ?? false;
    }

    public function isBundledWithAp(): bool
    {
        return $this->catalogSync?->isBundledWithAp() ?? false;
    }
}
