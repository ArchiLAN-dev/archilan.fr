<?php

declare(strict_types=1);

namespace App\GameSelection\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uq_game_requests_user_game', columns: ['user_id', 'normalized_name'])]
class GameRequest
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'game_name', type: 'string', length: 255)]
        private string $gameName,
        #[ORM\Column(name: 'normalized_name', type: 'string', length: 255)]
        private string $normalizedName,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(string $gameName, string $userId, \DateTimeImmutable $now): self
    {
        return new self(
            bin2hex(random_bytes(16)),
            trim($gameName),
            self::normalize($gameName),
            $userId,
            $now,
        );
    }

    public static function normalize(string $gameName): string
    {
        return mb_strtolower(trim($gameName));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getGameName(): string
    {
        return $this->gameName;
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
