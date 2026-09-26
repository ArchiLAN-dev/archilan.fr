<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Repository;

use App\GameSelection\Domain\Entity\ApworldHealth;

interface ApworldHealthRepositoryInterface
{
    public function save(ApworldHealth $health): void;

    public function find(string $gameId, string $apworldHash): ?ApworldHealth;

    public function flush(): void;
}
