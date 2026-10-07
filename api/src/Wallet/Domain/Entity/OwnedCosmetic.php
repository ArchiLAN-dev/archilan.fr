<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A shop cosmetic a member bought (story 41.7). Owned for good: retiring the item from the shop does not take it
 * back. Story 41.28: or won through an achievement or a quest - the source and its name say which.
 */
#[ORM\Entity]
#[ORM\Table(name: 'owned_cosmetic')]
#[ORM\UniqueConstraint(name: 'uniq_owned_cosmetic', columns: ['user_id', 'type', 'cosmetic_key'])]
final class OwnedCosmetic
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(type: 'string', length: 12)]
        private string $type,
        #[ORM\Column(name: 'cosmetic_key', type: 'string', length: 64)]
        private string $cosmeticKey,
        #[ORM\Column(name: 'acquired_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $acquiredAt,
        #[ORM\Column(type: 'string', length: 12, options: ['default' => 'shop'])]
        private string $source = self::SOURCE_SHOP,
        #[ORM\Column(name: 'source_label', type: 'string', length: 191, nullable: true)]
        private ?string $sourceLabel = null,
    ) {
    }

    public const string SOURCE_SHOP = 'shop';
    public const string SOURCE_ACHIEVEMENT = 'achievement';
    public const string SOURCE_QUEST = 'quest';

    public static function acquire(string $userId, string $type, string $cosmeticKey, \DateTimeImmutable $now, string $source = self::SOURCE_SHOP, ?string $sourceLabel = null): self
    {
        return new self(bin2hex(random_bytes(16)), $userId, $type, $cosmeticKey, $now, $source, null === $sourceLabel ? null : mb_substr($sourceLabel, 0, 191));
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getSourceLabel(): ?string
    {
        return $this->sourceLabel;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCosmeticKey(): string
    {
        return $this->cosmeticKey;
    }

    public function getAcquiredAt(): \DateTimeImmutable
    {
        return $this->acquiredAt;
    }
}
