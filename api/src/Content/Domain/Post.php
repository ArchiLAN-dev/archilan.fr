<?php

declare(strict_types=1);

namespace App\Content\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
final class Post
{
    public const TYPE_NEWS = 'news';
    public const TYPE_RECAP = 'recap';
    public const TYPE_ANNOUNCEMENT = 'announcement';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(type: 'string', length: 120, unique: true)]
        private string $slug,
        #[ORM\Column(type: 'string', length: 200)]
        private string $title,
        #[ORM\Column(type: 'string', length: 20)]
        private string $type,
        #[ORM\Column(type: 'string', length: 20)]
        private string $status,
        #[ORM\Column(type: 'text')]
        private string $excerpt,
        /** @var list<string> */
        #[ORM\Column(type: Types::JSON)]
        private array $body,
        #[ORM\Column(name: 'reading_time', type: 'string', length: 20)]
        private string $readingTime,
        #[ORM\Column(name: 'related_event_slug', type: 'string', length: 120, nullable: true)]
        private ?string $relatedEventSlug,
        #[ORM\Column(name: 'vod_url', type: 'string', length: 500, nullable: true)]
        private ?string $vodUrl,
        #[ORM\Column(name: 'cover_image_url', type: 'string', length: 2048, nullable: true)]
        private ?string $coverImageUrl,
        #[ORM\Column(name: 'published_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $publishedAt,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(name: 'cover_image_key', type: 'string', length: 500, nullable: true)]
        private ?string $coverImageKey = null,
    ) {
    }

    /**
     * @param list<string> $body
     */
    public static function draft(
        string $slug,
        string $title,
        string $type,
        string $excerpt,
        array $body,
        string $readingTime,
        ?string $relatedEventSlug,
        ?string $vodUrl,
        \DateTimeImmutable $now,
        ?string $coverImageUrl = null,
    ): self {
        return new self(
            bin2hex(random_bytes(16)),
            trim($slug),
            trim($title),
            $type,
            self::STATUS_DRAFT,
            trim($excerpt),
            $body,
            trim($readingTime),
            $relatedEventSlug,
            $vodUrl,
            self::nullableTrim($coverImageUrl),
            null,
            $now,
            $now,
        );
    }

    /**
     * @param list<string> $body
     */
    public function update(
        string $title,
        string $type,
        string $excerpt,
        array $body,
        string $readingTime,
        ?string $relatedEventSlug,
        ?string $vodUrl,
        ?string $coverImageUrl,
        \DateTimeImmutable $now,
    ): void {
        $this->title = trim($title);
        $this->type = $type;
        $this->excerpt = trim($excerpt);
        $this->body = $body;
        $this->readingTime = trim($readingTime);
        $this->relatedEventSlug = $relatedEventSlug;
        $this->vodUrl = $vodUrl;
        $this->coverImageUrl = self::nullableTrim($coverImageUrl);
        $this->updatedAt = $now;
    }

    public function publish(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_PUBLISHED;
        $this->publishedAt ??= $now;
        $this->updatedAt = $now;
    }

    public function unpublish(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_DRAFT;
        $this->updatedAt = $now;
    }

    public function isPublished(): bool
    {
        return self::STATUS_PUBLISHED === $this->status;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getExcerpt(): string
    {
        return $this->excerpt;
    }

    /** @return list<string> */
    public function getBody(): array
    {
        return $this->body;
    }

    public function getReadingTime(): string
    {
        return $this->readingTime;
    }

    public function getRelatedEventSlug(): ?string
    {
        return $this->relatedEventSlug;
    }

    public function getVodUrl(): ?string
    {
        return $this->vodUrl;
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

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
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
}
