<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure\Doctrine;

use App\GameSelection\Domain\Entity\ApworldHealth;
use App\GameSelection\Domain\Repository\ApworldHealthRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineApworldHealthRepository implements ApworldHealthRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(ApworldHealth $health): void
    {
        $this->entityManager->persist($health);
    }

    public function find(string $gameId, string $apworldHash): ?ApworldHealth
    {
        return $this->entityManager->getRepository(ApworldHealth::class)->findOneBy(['gameId' => $gameId, 'apworldHash' => $apworldHash]);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
