<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\GameSelection\Domain\ArchipelagoGame;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ApworldQuery
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findApworldMinioKey(string $sha256): ?string
    {
        $game = $this->entityManager->getRepository(ArchipelagoGame::class)
            ->findOneBy(['apworldHash' => $sha256]);

        if (!$game instanceof ArchipelagoGame) {
            return null;
        }

        return $game->getApworldMinioKey();
    }
}
