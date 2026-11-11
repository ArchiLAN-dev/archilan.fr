<?php

declare(strict_types=1);

namespace App\Content\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A help guide (story 53.5): what the pelles, the Passeur or the blind test are, written and corrected by the orga in
 * the admin. Read at `/aide/<slug>` once published; a draft shows nowhere on the public side.
 */
#[ORM\Entity]
#[ORM\Table(name: 'help_guide')]
#[ORM\UniqueConstraint(name: 'uniq_help_guide_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_help_guide_position', columns: ['position'])]
final class HelpGuide
{
    public const int MIN_SLUG = 2;
    public const int MAX_SLUG = 60;
    public const int MAX_TITLE = 80;
    public const int MAX_SUMMARY = 200;
    public const int MAX_BODY = 20000;

    /** Slugs taken by pages of their own: `/aide/archipelago` is the Archipelago guide. */
    public const array RESERVED_SLUGS = ['archipelago'];

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(type: 'string', length: 60)]
        private string $slug,
        #[ORM\Column(type: 'string', length: 80)]
        private string $title,
        #[ORM\Column(type: 'string', length: 200)]
        private string $summary,
        #[ORM\Column(type: 'text')]
        private string $body,
        #[ORM\Column(type: 'integer')]
        private int $position,
        #[ORM\Column(type: 'boolean')]
        private bool $published,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    /** @throws \DomainException with the code of the first invalid field */
    public static function create(
        string $id,
        string $slug,
        string $title,
        string $summary,
        string $body,
        bool $published,
        int $position,
        \DateTimeImmutable $now,
    ): self {
        $guide = new self($id, '', '', '', '', 0, false, $now, $now);
        $guide->edit($slug, $title, $summary, $body, $published, $now);
        $guide->moveTo($position, $now);

        return $guide;
    }

    /** @throws \DomainException with the code of the first invalid field */
    public function edit(string $slug, string $title, string $summary, string $body, bool $published, \DateTimeImmutable $now): void
    {
        $slug = trim($slug);
        $title = trim($title);
        $summary = trim($summary);
        $body = trim($body);
        if (!self::isAcceptedSlug($slug)) {
            throw new \DomainException('help_guide_slug_invalid');
        }
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            throw new \DomainException('help_guide_slug_reserved');
        }
        if ('' === $title || mb_strlen($title) > self::MAX_TITLE) {
            throw new \DomainException('help_guide_title_invalid');
        }
        if ('' === $summary || mb_strlen($summary) > self::MAX_SUMMARY) {
            throw new \DomainException('help_guide_summary_invalid');
        }
        if ('' === $body || mb_strlen($body) > self::MAX_BODY) {
            throw new \DomainException('help_guide_body_invalid');
        }

        $this->slug = $slug;
        $this->title = $title;
        $this->summary = $summary;
        $this->body = $body;
        $this->published = $published;
        $this->updatedAt = $now;
    }

    /** Lowercase letters, digits and inner hyphens, 2 to 60 characters: the end of `/aide/<slug>`. */
    public static function isAcceptedSlug(string $slug): bool
    {
        $length = strlen($slug);

        return $length >= self::MIN_SLUG && $length <= self::MAX_SLUG && 1 === preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug);
    }

    /** @throws \DomainException when the position is negative */
    public function moveTo(int $position, \DateTimeImmutable $now): void
    {
        if ($position < 0) {
            throw new \DomainException('help_guide_position_invalid');
        }
        $this->position = $position;
        $this->updatedAt = $now;
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

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function isPublished(): bool
    {
        return $this->published;
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
