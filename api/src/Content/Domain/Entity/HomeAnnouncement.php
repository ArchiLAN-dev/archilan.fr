<?php

declare(strict_types=1);

namespace App\Content\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An announcement opened as a modal on the home page between two dates (story 10.14): a title, a short text, an
 * optional image and a button leading to another page. Windows never overlap, so at most one runs at a time.
 */
#[ORM\Entity]
#[ORM\Table(name: 'home_announcement')]
#[ORM\Index(name: 'idx_home_announcement_window', columns: ['starts_at', 'ends_at'])]
final class HomeAnnouncement
{
    public const int MAX_TITLE = 120;
    public const int MAX_BODY = 600;
    public const int MAX_CTA_LABEL = 40;
    public const int MAX_CTA_URL = 500;

    public const string STATUS_UPCOMING = 'upcoming';
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_ENDED = 'ended';

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(type: 'string', length: 120)]
        private string $title,
        #[ORM\Column(type: 'text')]
        private string $body,
        #[ORM\Column(name: 'cta_label', type: 'string', length: 40)]
        private string $ctaLabel,
        #[ORM\Column(name: 'cta_url', type: 'string', length: 500)]
        private string $ctaUrl,
        #[ORM\Column(name: 'starts_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $startsAt,
        #[ORM\Column(name: 'ends_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $endsAt,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(name: 'image_key', type: 'string', length: 500, nullable: true)]
        private ?string $imageKey = null,
    ) {
    }

    /** @throws \DomainException with the code of the first invalid field */
    public static function create(
        string $id,
        string $title,
        string $body,
        string $ctaLabel,
        string $ctaUrl,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        \DateTimeImmutable $now,
    ): self {
        $announcement = new self($id, '', '', '', '', $startsAt, $endsAt, $now, $now);
        $announcement->edit($title, $body, $ctaLabel, $ctaUrl, $startsAt, $endsAt, $now);

        return $announcement;
    }

    /** @throws \DomainException with the code of the first invalid field */
    public function edit(
        string $title,
        string $body,
        string $ctaLabel,
        string $ctaUrl,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        \DateTimeImmutable $now,
    ): void {
        $title = trim($title);
        $body = trim($body);
        $ctaLabel = trim($ctaLabel);
        $ctaUrl = trim($ctaUrl);
        if ('' === $title || mb_strlen($title) > self::MAX_TITLE) {
            throw new \DomainException('home_announcement_title_invalid');
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            throw new \DomainException('home_announcement_body_invalid');
        }
        if ('' === $ctaLabel || mb_strlen($ctaLabel) > self::MAX_CTA_LABEL) {
            throw new \DomainException('home_announcement_cta_label_invalid');
        }
        if (!self::isAcceptedLink($ctaUrl)) {
            throw new \DomainException('home_announcement_cta_url_invalid');
        }
        if ($endsAt <= $startsAt || $endsAt <= $now) {
            throw new \DomainException('home_announcement_window_invalid');
        }

        $this->title = $title;
        $this->body = $body;
        $this->ctaLabel = $ctaLabel;
        $this->ctaUrl = $ctaUrl;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->updatedAt = $now;
    }

    /** A page of the site (`/...`, not `//host`) or an https address: no other scheme reaches a visitor's click. */
    public static function isAcceptedLink(string $url): bool
    {
        if ('' === $url || mb_strlen($url) > self::MAX_CTA_URL || 1 === preg_match('/\s/', $url)) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//') && !str_starts_with($url, '/\\');
        }

        return str_starts_with($url, 'https://') && false !== filter_var($url, \FILTER_VALIDATE_URL);
    }

    public function attachImage(string $key, \DateTimeImmutable $now): void
    {
        $this->imageKey = $key;
        $this->updatedAt = $now;
    }

    public function removeImage(\DateTimeImmutable $now): void
    {
        $this->imageKey = null;
        $this->updatedAt = $now;
    }

    public function statusAt(\DateTimeImmutable $now): string
    {
        if ($now < $this->startsAt) {
            return self::STATUS_UPCOMING;
        }

        return $now < $this->endsAt ? self::STATUS_RUNNING : self::STATUS_ENDED;
    }

    public function overlaps(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): bool
    {
        return $this->startsAt < $endsAt && $startsAt < $this->endsAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getCtaLabel(): string
    {
        return $this->ctaLabel;
    }

    public function getCtaUrl(): string
    {
        return $this->ctaUrl;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getImageKey(): ?string
    {
        return $this->imageKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
