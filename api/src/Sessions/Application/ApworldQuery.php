<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;

final readonly class ApworldQuery
{
    public function __construct(private GameRepositoryInterface $games)
    {
    }

    public function findApworldMinioKey(string $sha256): ?string
    {
        $game = $this->games->findByApworldHash($sha256);

        if (!$game instanceof Game) {
            return null;
        }

        return $game->getApworldMinioKey();
    }
}
