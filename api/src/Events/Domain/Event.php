<?php

declare(strict_types=1);

namespace App\Events\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'events')]
final class Event
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_IN_PROGRESS = 'in-progress';
    public const STATUS_COMPLETED = 'completed';

    public const PUBLIC_STATUSES = [
        self::STATUS_PUBLISHED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
    ];

    /**
     * @return list<string>
     */
    public static function supportedStatuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_PUBLISHED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
        ];
    }

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(type: 'string', length: 120)]
        private string $title,
        #[ORM\Column(type: 'text')]
        private string $description,
        #[ORM\Column(type: 'string', length: 20)]
        private string $status,
        #[ORM\Column(name: 'starts_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $startsAt,
        #[ORM\Column(name: 'ends_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $endsAt,
        #[ORM\Column(type: 'string', length: 160)]
        private string $venue,
        #[ORM\Column(type: 'integer')]
        private int $capacity,
        #[ORM\Column(name: 'registration_opens_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $registrationOpensAt,
        #[ORM\Column(name: 'registration_closes_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $registrationClosesAt,
        #[ORM\Column(name: 'is_public', type: 'boolean')]
        private bool $isPublic,
        #[ORM\Column(name: 'private_access_password_hash', type: 'string', length: 255, nullable: true)]
        private ?string $privateAccessPasswordHash,
        #[ORM\Column(name: 'game_selection_enabled', type: 'boolean')]
        private bool $gameSelectionEnabled,
        /** @var list<array{gameId: string}> */
        #[ORM\Column(name: 'game_selection_config', type: Types::JSON)]
        private array $gameSelectionConfig,
        #[ORM\Column(name: 'vod_url', type: 'string', length: 500, nullable: true)]
        private ?string $vodUrl,
        #[ORM\Column(name: 'recap_post_slug', type: 'string', length: 120, nullable: true)]
        private ?string $recapPostSlug,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(name: 'game_selection_max', type: 'integer', nullable: true)]
        private ?int $gameSelectionMaxPerRegistrant = null,
        #[ORM\Column(name: 'capacity_notification_sent_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $capacityNotificationSentAt = null,
        #[ORM\Column(name: 'helloasso_form_slug', type: 'string', length: 120, nullable: true)]
        private ?string $helloassoFormSlug = null,
        #[ORM\Column(name: 'cover_image_url', type: 'string', length: 2048, nullable: true)]
        private ?string $coverImageUrl = null,
        /** @var list<mixed>|null */
        #[ORM\Column(name: 'photo_gallery', type: Types::JSON, nullable: true)]
        private ?array $photoGallery = null,
        #[ORM\Column(name: 'cover_image_key', type: 'string', length: 500, nullable: true)]
        private ?string $coverImageKey = null,
    ) {
    }

    /**
     * @param list<string|array{source: string, url?: string, key?: string}>|null $photoGallery
     */
    public static function draft(
        string $title,
        string $description,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        string $venue,
        int $capacity,
        \DateTimeImmutable $registrationOpensAt,
        \DateTimeImmutable $registrationClosesAt,
        bool $isPublic,
        \DateTimeImmutable $now,
        ?string $coverImageUrl = null,
        ?array $photoGallery = null,
    ): self {
        return new self(
            bin2hex(random_bytes(16)),
            trim($title),
            trim($description),
            self::STATUS_DRAFT,
            $startsAt,
            $endsAt,
            trim($venue),
            $capacity,
            $registrationOpensAt,
            $registrationClosesAt,
            $isPublic,
            null,
            false,
            [],
            null,
            null,
            $now,
            $now,
            coverImageUrl: self::nullableTrim($coverImageUrl),
            photoGallery: self::normalizePhotoGallery($photoGallery),
        );
    }

    /**
     * @param list<string|array{source: string, url?: string, key?: string}>|null $photoGallery
     */
    public function updateDetails(
        string $title,
        string $description,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        string $venue,
        int $capacity,
        \DateTimeImmutable $registrationOpensAt,
        \DateTimeImmutable $registrationClosesAt,
        bool $isPublic,
        \DateTimeImmutable $now,
        ?string $coverImageUrl = null,
        ?array $photoGallery = null,
    ): void {
        $this->title = trim($title);
        $this->description = trim($description);
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->venue = trim($venue);
        $this->capacity = $capacity;
        $this->registrationOpensAt = $registrationOpensAt;
        $this->registrationClosesAt = $registrationClosesAt;
        $this->isPublic = $isPublic;
        $this->coverImageUrl = self::nullableTrim($coverImageUrl);
        $this->photoGallery = self::normalizePhotoGallery($photoGallery);
        $this->updatedAt = $now;
    }

    public function transitionTo(string $targetStatus, \DateTimeImmutable $now): void
    {
        if (!in_array($targetStatus, self::supportedStatuses(), true)) {
            throw new \DomainException('Unsupported event status.');
        }

        if ($targetStatus === $this->status) {
            return;
        }

        $allowedTransitions = [
            self::STATUS_DRAFT => [self::STATUS_PUBLISHED],
            self::STATUS_PUBLISHED => [self::STATUS_DRAFT, self::STATUS_IN_PROGRESS],
            self::STATUS_IN_PROGRESS => [self::STATUS_PUBLISHED, self::STATUS_COMPLETED],
            self::STATUS_COMPLETED => [self::STATUS_PUBLISHED],
        ];

        if (!in_array($targetStatus, $allowedTransitions[$this->status] ?? [], true)) {
            throw new \DomainException(sprintf('Cannot transition event from %s to %s.', $this->status, $targetStatus));
        }

        $this->status = $targetStatus;
        $this->updatedAt = $now;
    }

    public function isVisiblePublicly(): bool
    {
        return in_array($this->status, self::PUBLIC_STATUSES, true);
    }

    public function configurePrivateAccessPassword(string $passwordHash, \DateTimeImmutable $now): void
    {
        // Application layer already validates isPublic; guard enforces the domain invariant directly.
        if ($this->isPublic) {
            throw new \DomainException('Private access password can only be configured on private events.');
        }

        $this->privateAccessPasswordHash = $passwordHash;
        $this->updatedAt = $now;
    }

    public function hasPrivateAccessPassword(): bool
    {
        return null !== $this->privateAccessPasswordHash;
    }

    public function verifyPrivateAccessPassword(string $password): bool
    {
        if (null === $this->privateAccessPasswordHash) {
            return false;
        }

        return password_verify($password, $this->privateAccessPasswordHash);
    }

    public function isAtCapacity(int $confirmedCount): bool
    {
        return $confirmedCount >= $this->capacity;
    }

    public function isCapacityNotificationSent(): bool
    {
        return null !== $this->capacityNotificationSentAt;
    }

    public function markCapacityNotificationSent(\DateTimeImmutable $now): void
    {
        $this->capacityNotificationSentAt = $now;
        $this->updatedAt = $now;
    }

    public function getHelloassoFormSlug(): ?string
    {
        return $this->helloassoFormSlug;
    }

    public function setHelloassoFormSlug(?string $slug, \DateTimeImmutable $now): void
    {
        $this->helloassoFormSlug = self::nullableTrim($slug);
        $this->updatedAt = $now;
    }

    public function getCoverImageUrl(): ?string
    {
        return $this->coverImageUrl;
    }

    public function getCoverImageKey(): ?string
    {
        return $this->coverImageKey;
    }

    public function setCoverImageKey(string $key, ?\DateTimeImmutable $now = null): void
    {
        $this->coverImageKey = $key;
        if (null !== $now) {
            $this->updatedAt = $now;
        }
    }

    public function clearCoverImageKey(?\DateTimeImmutable $now = null): void
    {
        $this->coverImageKey = null;
        if (null !== $now) {
            $this->updatedAt = $now;
        }
    }

    /**
     * @return list<array{source: string, url?: string, key?: string}>
     */
    public function getPhotoGallery(): array
    {
        /** @var list<array{source: string, url?: string, key?: string}> $result */
        $result = [];
        foreach ($this->photoGallery ?? [] as $rawItem) {
            if (is_string($rawItem) && '' !== $rawItem) {
                $result[] = ['source' => 'url', 'url' => $rawItem];
                continue;
            }
            if (!is_array($rawItem)) {
                continue;
            }
            $source = $rawItem['source'] ?? null;
            if (!is_string($source) || '' === $source) {
                continue;
            }
            /** @var array{source: string, url?: string, key?: string} $entry */
            $entry = ['source' => $source];
            $url = $rawItem['url'] ?? null;
            if (is_string($url) && '' !== $url) {
                $entry['url'] = $url;
            }
            $key = $rawItem['key'] ?? null;
            if (is_string($key) && '' !== $key) {
                $entry['key'] = $key;
            }
            $result[] = $entry;
        }

        return $result;
    }

    public function getPhotoGalleryCount(): int
    {
        return count($this->getPhotoGallery());
    }

    public function appendGalleryUpload(string $key): void
    {
        $gallery = $this->getPhotoGallery();
        $gallery[] = ['source' => 'upload', 'key' => $key];
        $this->photoGallery = $gallery;
    }

    public function removeGalleryItem(int $index): bool
    {
        $gallery = $this->getPhotoGallery();
        if ($index < 0 || $index >= count($gallery)) {
            return false;
        }
        array_splice($gallery, $index, 1);
        $this->photoGallery = [] === $gallery ? null : $gallery;

        return true;
    }

    /**
     * @param list<array{gameId: string}> $config
     */
    public function configureGameSelection(bool $enabled, array $config, \DateTimeImmutable $now, ?int $maxPerRegistrant = null): void
    {
        $this->gameSelectionEnabled = $enabled;
        $this->gameSelectionConfig = $config;
        $this->gameSelectionMaxPerRegistrant = $maxPerRegistrant;
        $this->updatedAt = $now;
    }

    public function isGameSelectionEnabled(): bool
    {
        return $this->gameSelectionEnabled;
    }

    public function getGameSelectionMaxPerRegistrant(): ?int
    {
        return $this->gameSelectionMaxPerRegistrant;
    }

    /**
     * @return list<array{gameId: string}>
     */
    public function getGameSelectionConfig(): array
    {
        return $this->gameSelectionConfig;
    }

    public function attachRecap(?string $vodUrl, ?string $recapPostSlug, \DateTimeImmutable $now): void
    {
        if (self::STATUS_COMPLETED !== $this->status) {
            throw new \DomainException('Recap can only be attached to completed events.');
        }

        $this->vodUrl = $vodUrl;
        $this->recapPostSlug = $recapPostSlug;
        $this->updatedAt = $now;
    }

    public function getVodUrl(): ?string
    {
        return $this->vodUrl;
    }

    public function getRecapPostSlug(): ?string
    {
        return $this->recapPostSlug;
    }

    public function hasRecap(): bool
    {
        return null !== $this->vodUrl || null !== $this->recapPostSlug;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getVenue(): string
    {
        return $this->venue;
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function getRegistrationOpensAt(): \DateTimeImmutable
    {
        return $this->registrationOpensAt;
    }

    public function getRegistrationClosesAt(): \DateTimeImmutable
    {
        return $this->registrationClosesAt;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function nullableTrim(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * @param list<string|array{source: string, url?: string, key?: string}>|null $items
     *
     * @return list<array{source: string, url?: string, key?: string}>|null
     */
    private static function normalizePhotoGallery(?array $items): ?array
    {
        if (null === $items) {
            return null;
        }

        $normalized = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $trimmed = trim($item);
                if ('' !== $trimmed) {
                    $normalized[] = ['source' => 'url', 'url' => $trimmed];
                }
                continue;
            }

            if ('upload' === $item['source'] && isset($item['key']) && '' !== trim($item['key'])) {
                $normalized[] = ['source' => 'upload', 'key' => trim($item['key'])];
                continue;
            }

            if ('url' === $item['source'] && isset($item['url']) && '' !== trim($item['url'])) {
                $normalized[] = ['source' => 'url', 'url' => trim($item['url'])];
            }
        }

        return [] === $normalized ? null : $normalized;
    }
}
