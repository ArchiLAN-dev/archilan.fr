<?php

declare(strict_types=1);

namespace App\GameSelection\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Single global "Installer Archipelago" guide (story 31.3): ordered install steps shared across all
 * games, so per-game tutorials don't repeat the basics. Same step shape as {@see Game} install steps.
 * One row, identified by {@see SINGLETON_ID}.
 */
#[ORM\Entity]
final class ArchipelagoGuide
{
    public const SINGLETON_ID = 'default';

    /**
     * @param list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}> $steps
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(type: 'json')]
        private array $steps,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}> $steps
     */
    public static function create(array $steps, \DateTimeImmutable $now): self
    {
        return new self(self::SINGLETON_ID, $steps, $now);
    }

    /**
     * @param list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}> $steps
     */
    public function update(array $steps, \DateTimeImmutable $now): void
    {
        $this->steps = $steps;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
