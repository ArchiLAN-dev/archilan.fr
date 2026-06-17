<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A member's named, reusable Archipelago YAML configuration for a given game. Private to its owner;
 * applied to a personal-run slot from the slot YAML editor (story 16.11).
 */
#[ORM\Entity]
#[ORM\Table(name: 'yaml_template')]
#[ORM\UniqueConstraint(name: 'uniq_yaml_template_user_game_name', columns: ['user_id', 'game_id', 'name'])]
#[ORM\Index(name: 'idx_yaml_template_user_game', columns: ['user_id', 'game_id'])]
final class YamlTemplate
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'game_id', type: 'string', length: 32)]
        private string $gameId,
        #[ORM\Column(type: 'string', length: 80)]
        private string $name,
        #[ORM\Column(type: 'text')]
        private string $yaml,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(string $userId, string $gameId, string $name, string $yaml, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $userId, $gameId, $name, $yaml, $now, $now);
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->userId === $userId;
    }

    public function rename(string $name, \DateTimeImmutable $now): void
    {
        $this->name = $name;
        $this->updatedAt = $now;
    }

    public function updateYaml(string $yaml, \DateTimeImmutable $now): void
    {
        $this->yaml = $yaml;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getGameId(): string
    {
        return $this->gameId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getYaml(): string
    {
        return $this->yaml;
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
