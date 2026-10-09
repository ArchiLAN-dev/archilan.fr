<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A member's personal friend link (story 43.3): `/ami/{code}`, shown as a QR code at a LAN so a neighbour adds
 * them without spelling their pseudo. The code is opaque, distinct from the slug, and regenerating it kills the
 * old one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_friend_link')]
#[ORM\UniqueConstraint(name: 'uniq_community_friend_link_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_community_friend_link_code', columns: ['code'])]
final class FriendLink
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(type: 'string', length: 16)]
        private string $code,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'regenerated_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $regeneratedAt = null,
    ) {
    }

    public static function create(string $userId, string $code, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $userId, $code, $now);
    }

    /** The old code stops working at once. */
    public function regenerate(string $code, \DateTimeImmutable $now): void
    {
        $this->code = $code;
        $this->regeneratedAt = $now;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRegeneratedAt(): ?\DateTimeImmutable
    {
        return $this->regeneratedAt;
    }
}
