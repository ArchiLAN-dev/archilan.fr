<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A named group of a member's friends (story 43.13), « La team du jeudi »: a private label of its owner, never shown
 * to the friends in it. Used to invite them all at once and to filter the directory.
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_friend_group')]
#[ORM\Index(name: 'idx_community_friend_group_owner', columns: ['owner_id'])]
final class FriendGroup
{
    public const int MAX_PER_OWNER = 20;
    public const int MAX_MEMBERS = 30;
    public const int MAX_NAME_LENGTH = 60;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'owner_id', type: 'string', length: 32)]
        private string $ownerId,
        #[ORM\Column(type: 'string', length: 60)]
        private string $name,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    /** The name as it is kept, or null when it is empty or too long. */
    public static function normalizeName(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        return '' === $name || mb_strlen($name) > self::MAX_NAME_LENGTH ? null : $name;
    }

    /** `$name` comes out of normalizeName(). */
    public static function create(string $ownerId, string $name, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $ownerId, $name, $now, $now);
    }

    /** `$name` comes out of normalizeName(). */
    public function rename(string $name, \DateTimeImmutable $now): void
    {
        $this->name = $name;
        $this->updatedAt = $now;
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->ownerId === $userId;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
