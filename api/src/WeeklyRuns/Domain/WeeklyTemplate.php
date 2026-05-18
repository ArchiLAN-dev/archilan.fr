<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'weekly_templates')]
final class WeeklyTemplate
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,
        #[ORM\Column(name: 'game_id', type: Types::STRING, length: 32)]
        private string $gameId,
        #[ORM\Column(name: 'yaml_config', type: Types::TEXT)]
        private string $yamlConfig,
        #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
        private ?string $name,
        #[ORM\Column(name: 'max_attempts', type: Types::INTEGER, nullable: true)]
        private ?int $maxAttempts,
        #[ORM\Column(name: 'is_active', type: Types::BOOLEAN)]
        private bool $isActive,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public function deactivate(\DateTimeImmutable $now): void
    {
        $this->isActive = false;
        $this->updatedAt = $now;
    }

    /**
     * @param array{name?: string|null, yamlConfig?: string, maxAttempts?: int|null, isActive?: bool} $changes
     */
    public function applyChanges(array $changes, \DateTimeImmutable $now): void
    {
        if (array_key_exists('name', $changes)) {
            $this->name = $changes['name'];
        }
        if (array_key_exists('yamlConfig', $changes)) {
            $this->yamlConfig = $changes['yamlConfig'];
        }
        if (array_key_exists('maxAttempts', $changes)) {
            $this->maxAttempts = $changes['maxAttempts'];
        }
        if (array_key_exists('isActive', $changes)) {
            $this->isActive = $changes['isActive'];
        }
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getGameId(): string
    {
        return $this->gameId;
    }

    public function getYamlConfig(): string
    {
        return $this->yamlConfig;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getMaxAttempts(): ?int
    {
        return $this->maxAttempts;
    }

    public function isActive(): bool
    {
        return $this->isActive;
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
