<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\GameSelection\Domain\Game;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ApworldQuery
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findApworldMinioKey(string $sha256): ?string
    {
        $game = $this->entityManager->getRepository(Game::class)
            ->findOneBy(['apworldHash' => $sha256]);

        if (!$game instanceof Game) {
            return null;
        }

        return $game->getApworldMinioKey();
    }
}
