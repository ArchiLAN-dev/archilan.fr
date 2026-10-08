<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use App\Community\Domain\ValueObject\CosmeticReward;
use Doctrine\ORM\Mapping as ORM;

/**
 * A series of achievements read as a whole (story 30.52): Re:Zero, the LAN, the weekly runs. An achievement belongs
 * to at most one collection. A secret collection stays hidden from a member until they unlock one of its
 * achievements. Completing it (every active achievement of it unlocked) gives its reward once: a cosmetic, pelles,
 * or both.
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_achievement_collection')]
final class AchievementCollection
{
    public const int MAX_NAME = 120;

    public const int MAX_DESCRIPTION = 500;

    public const int MAX_PELLES = 100000;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(type: 'string', length: 120)]
        private string $name,
        #[ORM\Column(type: 'text')]
        private string $description,
        #[ORM\Column(type: 'integer')]
        private int $position,
        #[ORM\Column(type: 'boolean')]
        private bool $secret,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(name: 'image_key', type: 'string', length: 512, nullable: true)]
        private ?string $imageKey = null,
        #[ORM\Column(name: 'cosmetic_type', type: 'string', length: 12, nullable: true)]
        private ?string $cosmeticType = null,
        #[ORM\Column(name: 'cosmetic_key', type: 'string', length: 64, nullable: true)]
        private ?string $cosmeticKey = null,
        #[ORM\Column(name: 'reward_pelles', type: 'integer', options: ['default' => 0])]
        private int $rewardPelles = 0,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the name is empty or the pelles out of range
     */
    public static function create(string $name, string $description, int $position, \DateTimeImmutable $now): self
    {
        $collection = new self(bin2hex(random_bytes(16)), '', '', $position, false, $now, $now);
        $collection->rename($name, $description, $now);

        return $collection;
    }

    /**
     * @throws \InvalidArgumentException when the name is empty
     */
    public function rename(string $name, string $description, \DateTimeImmutable $now): void
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \InvalidArgumentException('Le nom de la collection est requis.');
        }
        $this->name = mb_substr($name, 0, self::MAX_NAME);
        $this->description = mb_substr(trim($description), 0, self::MAX_DESCRIPTION);
        $this->updatedAt = $now;
    }

    public function updateImage(?string $key, \DateTimeImmutable $now): void
    {
        $this->imageKey = $key;
        $this->updatedAt = $now;
    }

    public function markSecret(bool $secret, \DateTimeImmutable $now): void
    {
        $this->secret = $secret;
        $this->updatedAt = $now;
    }

    /**
     * @throws \InvalidArgumentException when the pelles are negative or above the cap
     */
    public function rewardWith(?CosmeticReward $cosmetic, int $pelles, \DateTimeImmutable $now): void
    {
        if ($pelles < 0 || $pelles > self::MAX_PELLES) {
            throw new \InvalidArgumentException(sprintf('Les pelles vont de 0 à %d.', self::MAX_PELLES));
        }
        $this->cosmeticType = $cosmetic?->type;
        $this->cosmeticKey = $cosmetic?->key;
        $this->rewardPelles = $pelles;
        $this->updatedAt = $now;
    }

    public function reorder(int $position, \DateTimeImmutable $now): void
    {
        $this->position = $position;
        $this->updatedAt = $now;
    }

    public function getCosmeticReward(): ?CosmeticReward
    {
        try {
            return CosmeticReward::fromParts($this->cosmeticType, $this->cosmeticKey);
        } catch (\DomainException) {
            return null;
        }
    }

    public function getRewardPelles(): int
    {
        return $this->rewardPelles;
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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function isSecret(): bool
    {
        return $this->secret;
    }

    public function getImageKey(): ?string
    {
        return $this->imageKey;
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
