<?php

declare(strict_types=1);

namespace App\Community\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A configurable achievement (story 30.16): metadata + a composable unlock rule tree, stored in the
 * database and managed from the admin form. `key` is immutable (grants reference it); the recompute is
 * deterministic + monotonic and evaluates `rule` against a MetricBag.
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_achievement_definition')]
#[ORM\UniqueConstraint(name: 'uniq_community_achievement_key', columns: ['achievement_key'])]
final class AchievementDefinition
{
    /**
     * @param array<string, mixed> $rule
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'achievement_key', type: 'string', length: 64)]
        private string $key,
        #[ORM\Column(type: 'string', length: 191)]
        private string $name,
        #[ORM\Column(type: 'text')]
        private string $description,
        #[ORM\Column(type: 'json')]
        private array $rule,
        #[ORM\Column(type: 'boolean')]
        private bool $active,
        #[ORM\Column(type: 'integer')]
        private int $position,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $rule
     */
    public static function create(string $key, string $name, string $description, array $rule, int $position, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $key, $name, $description, $rule, true, $position, $now, $now);
    }

    /**
     * @param array<string, mixed> $rule
     */
    public function update(string $name, string $description, array $rule, \DateTimeImmutable $now): void
    {
        $this->name = $name;
        $this->description = $description;
        $this->rule = $rule;
        $this->updatedAt = $now;
    }

    public function setActive(bool $active, \DateTimeImmutable $now): void
    {
        $this->active = $active;
        $this->updatedAt = $now;
    }

    public function reorder(int $position, \DateTimeImmutable $now): void
    {
        $this->position = $position;
        $this->updatedAt = $now;
    }

    /** Evaluate the stored rule tree; a malformed rule never unlocks (defensive). */
    public function matches(MetricBag $bag): bool
    {
        try {
            return AchievementRuleFactory::fromArray($this->rule)->matches($bag);
        } catch (InvalidAchievementRuleException) {
            return false;
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRule(): array
    {
        return $this->rule;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getPosition(): int
    {
        return $this->position;
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
