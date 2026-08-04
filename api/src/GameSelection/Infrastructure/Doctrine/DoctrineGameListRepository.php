<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure\Doctrine;

use App\GameSelection\Domain\Entity\GameListEntry;
use App\GameSelection\Domain\Enum\GameListKind;
use App\GameSelection\Domain\Repository\GameListRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineGameListRepository implements GameListRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findGameIds(string $userId, GameListKind $kind): array
    {
        $rows = $this->entityManager->getRepository(GameListEntry::class)
            ->findBy(['userId' => $userId, 'kind' => $kind]);

        return array_map(static fn (GameListEntry $entry): string => $entry->getGameId(), $rows);
    }

    public function find(string $userId, string $gameId, GameListKind $kind): ?GameListEntry
    {
        return $this->entityManager->getRepository(GameListEntry::class)
            ->findOneBy(['userId' => $userId, 'gameId' => $gameId, 'kind' => $kind]);
    }

    public function save(GameListEntry $entry): void
    {
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    public function delete(GameListEntry $entry): void
    {
        $this->entityManager->remove($entry);
        $this->entityManager->flush();
    }
}
