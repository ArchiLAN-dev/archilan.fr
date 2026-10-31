<?php

declare(strict_types=1);

namespace App\Content\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A partner of the association (story 45.1): a name, a short line, a link to its site and a logo. No tiers: one flat
 * list, ordered by the admin. Visitors only see a partner that is shown and has a logo.
 */
#[ORM\Entity]
#[ORM\Table(name: 'partner')]
#[ORM\Index(name: 'idx_partner_position', columns: ['position'])]
final class Partner
{
    public const int MAX_NAME = 80;
    public const int MAX_DESCRIPTION = 250;
    public const int MAX_URL = 500;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(type: 'string', length: 80)]
        private string $name,
        #[ORM\Column(type: 'string', length: 250)]
        private string $description,
        #[ORM\Column(type: 'string', length: 500)]
        private string $url,
        #[ORM\Column(type: 'boolean')]
        private bool $active,
        #[ORM\Column(type: 'integer')]
        private int $position,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(name: 'logo_key', type: 'string', length: 500, nullable: true)]
        private ?string $logoKey = null,
        /** Story 45.5: the logo is drawn for a dark background (white text...), so it sits on a dark chip. */
        #[ORM\Column(name: 'logo_on_dark', type: 'boolean', options: ['default' => false])]
        private bool $logoOnDark = false,
    ) {
    }

    /** @throws \DomainException with the code of the first invalid field */
    public static function create(
        string $id,
        string $name,
        string $description,
        string $url,
        bool $active,
        int $position,
        \DateTimeImmutable $now,
        bool $logoOnDark = false,
    ): self {
        $partner = new self($id, '', '', '', false, 0, $now, $now);
        $partner->edit($name, $description, $url, $active, $now, $logoOnDark);
        $partner->moveTo($position, $now);

        return $partner;
    }

    /** @throws \DomainException with the code of the first invalid field */
    public function edit(string $name, string $description, string $url, bool $active, \DateTimeImmutable $now, bool $logoOnDark = false): void
    {
        $name = trim($name);
        $description = trim($description);
        $url = trim($url);
        if ('' === $name || mb_strlen($name) > self::MAX_NAME) {
            throw new \DomainException('partner_name_invalid');
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new \DomainException('partner_description_invalid');
        }
        if (!self::isAcceptedUrl($url)) {
            throw new \DomainException('partner_url_invalid');
        }

        $this->name = $name;
        $this->description = $description;
        $this->url = $url;
        $this->active = $active;
        $this->logoOnDark = $logoOnDark;
        $this->updatedAt = $now;
    }

    /** An https address only: a partner is another site, never a page of ours nor another scheme. */
    public static function isAcceptedUrl(string $url): bool
    {
        if ('' === $url || mb_strlen($url) > self::MAX_URL || 1 === preg_match('/\s/', $url)) {
            return false;
        }

        return str_starts_with($url, 'https://') && false !== filter_var($url, \FILTER_VALIDATE_URL);
    }

    /** @throws \DomainException when the position is negative */
    public function moveTo(int $position, \DateTimeImmutable $now): void
    {
        if ($position < 0) {
            throw new \DomainException('partner_position_invalid');
        }
        $this->position = $position;
        $this->updatedAt = $now;
    }

    public function attachLogo(string $key, \DateTimeImmutable $now): void
    {
        $this->logoKey = $key;
        $this->updatedAt = $now;
    }

    public function removeLogo(\DateTimeImmutable $now): void
    {
        $this->logoKey = null;
        $this->updatedAt = $now;
    }

    /** What visitors see: shown by the admin, and with a logo to show. */
    public function isVisible(): bool
    {
        return $this->active && null !== $this->logoKey;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function isLogoOnDark(): bool
    {
        return $this->logoOnDark;
    }

    public function getLogoKey(): ?string
    {
        return $this->logoKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
