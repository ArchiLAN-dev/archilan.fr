<?php

declare(strict_types=1);

namespace App\GameSelection\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'games')]
#[ORM\UniqueConstraint(name: 'uniq_games_slug', columns: ['slug'])]
class ArchipelagoGame
{
    public const AVAILABILITY_AVAILABLE = 'available';
    public const AVAILABILITY_UNAVAILABLE = 'unavailable';
    public const AVAILABILITY_EXPERIMENTAL = 'experimental';

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
        #[ORM\Column(name: 'cover_image_url', type: 'string', length: 500, nullable: true)]
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
}
