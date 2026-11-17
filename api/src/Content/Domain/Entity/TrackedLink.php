<?php

declare(strict_types=1);

namespace App\Content\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A tracked link (story 55.1): `/l/{code}` counts a visit, then sends to the target. A target on the site (a path)
 * makes an inbound link, one elsewhere (`https://`) an outbound one. The code never changes: it may be printed already.
 * Story 55.3: a partner's link follows its partner (label, target, state) and is changed only through it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tracked_link')]
#[ORM\UniqueConstraint(name: 'uniq_tracked_link_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_tracked_link_partner', columns: ['partner_id'])]
final class TrackedLink
{
    public const int MAX_LABEL = 120;
    public const int MAX_TARGET = 500;
    private const string CODE_PATTERN = '/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/';

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(type: 'string', length: 40)]
        private string $code,
        #[ORM\Column(type: 'string', length: 120)]
        private string $label,
        #[ORM\Column(type: 'string', length: 500)]
        private string $target,
        #[ORM\Column(type: 'boolean')]
        private bool $active,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        /** Story 55.3: the partner this link leads to; it stays once the partner is deleted (the link is off then). */
        #[ORM\Column(name: 'partner_id', type: 'string', length: 32, nullable: true)]
        private ?string $partnerId = null,
    ) {
    }

    /** @throws \DomainException with the code of the first invalid field */
    public static function create(string $id, string $code, string $label, string $target, \DateTimeImmutable $now): self
    {
        if (1 !== preg_match(self::CODE_PATTERN, $code)) {
            throw new \DomainException('tracked_link_code_invalid');
        }
        $link = new self($id, $code, '', '', true, $now, $now);
        $link->edit($label, $target, true, $now);

        return $link;
    }

    /**
     * Story 55.3: the link of a partner, on and off with it.
     *
     * @throws \DomainException with the code of the first invalid field
     */
    public static function forPartner(string $id, string $code, string $partnerId, string $partnerName, string $partnerUrl, bool $active, \DateTimeImmutable $now): self
    {
        $link = self::create($id, $code, self::partnerLabel($partnerName), $partnerUrl, $now);
        $link->partnerId = $partnerId;
        $link->edit($link->label, $partnerUrl, $active, $now);

        return $link;
    }

    /**
     * Story 55.3: the partner changed (name, address, shown or hidden) or was deleted (`$active` false).
     *
     * @throws \DomainException with the code of the first invalid field
     */
    public function followPartner(string $partnerName, string $partnerUrl, bool $active, \DateTimeImmutable $now): void
    {
        $this->edit(self::partnerLabel($partnerName), $partnerUrl, $active, $now);
    }

    public static function partnerLabel(string $partnerName): string
    {
        return 'Partenaire : '.trim($partnerName);
    }

    /** @throws \DomainException with the code of the first invalid field */
    public function edit(string $label, string $target, bool $active, \DateTimeImmutable $now): void
    {
        $label = trim($label);
        $target = trim($target);
        if ('' === $label || mb_strlen($label) > self::MAX_LABEL) {
            throw new \DomainException('tracked_link_label_invalid');
        }
        if (!self::isValidTarget($target)) {
            throw new \DomainException('tracked_link_target_invalid');
        }
        $this->label = $label;
        $this->target = $target;
        $this->active = $active;
        $this->updatedAt = $now;
    }

    /**
     * A path of the site (`/...`, never `//host` nor a backslash, which browsers read as another host), or an https
     * address. Nothing else: no `http:`, no `javascript:`.
     */
    public static function isValidTarget(string $target): bool
    {
        if ('' === $target || mb_strlen($target) > self::MAX_TARGET || 1 === preg_match('/[\s\\\\]/', $target)) {
            return false;
        }
        if (str_starts_with($target, '/')) {
            return !str_starts_with($target, '//');
        }
        if (!str_starts_with($target, 'https://')) {
            return false;
        }
        $host = parse_url($target, \PHP_URL_HOST);

        return is_string($host) && '' !== $host;
    }

    public static function isValidCode(string $code): bool
    {
        return 1 === preg_match(self::CODE_PATTERN, $code);
    }

    public function isOutbound(): bool
    {
        return !str_starts_with($this->target, '/');
    }

    public function getPartnerId(): ?string
    {
        return $this->partnerId;
    }

    public function isPartnerLink(): bool
    {
        return null !== $this->partnerId;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function isActive(): bool
    {
        return $this->active;
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
