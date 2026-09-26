<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Entity\ApworldHealth;
use App\GameSelection\Domain\Repository\ApworldHealthRepositoryInterface;

/**
 * Test double for the apworld health memory (story 38.9).
 */
final class InMemoryApworldHealthRepository implements ApworldHealthRepositoryInterface
{
    /** @var array<string, ApworldHealth> */
    private array $health = [];

    public function save(ApworldHealth $health): void
    {
        $this->health[$health->getGameId()."\0".$health->getApworldHash()] = $health;
    }

    public function find(string $gameId, string $apworldHash): ?ApworldHealth
    {
        return $this->health[$gameId."\0".$apworldHash] ?? null;
    }

    public function flush(): void
    {
    }
}
