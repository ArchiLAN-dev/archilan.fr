<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameCatalogSync;
use App\GameSelection\Domain\GameRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineGameRepository implements GameRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?Game
    {
        return $this->entityManager->find(Game::class, $id);
    }

    public function findBySlug(string $slug): ?Game
    {
        /* @var Game|null */
        return $this->entityManager->getRepository(Game::class)->findOneBy(['slug' => $slug]);
    }

    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /* @var list<Game> */
        return $this->entityManager->getRepository(Game::class)->findBy(['id' => $ids]);
    }

    public function findByIdsSortedByName(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /* @var list<Game> */
        return $this->entityManager->getRepository(Game::class)->findBy(['id' => $ids], ['name' => 'ASC']);
    }

    public function findAllSortedByName(): array
    {
        /* @var list<Game> */
        return $this->entityManager->getRepository(Game::class)->findBy([], ['name' => 'ASC']);
    }

    public function findByAvailabilitiesSortedByName(array $availabilities): array
    {
        if ([] === $availabilities) {
            return [];
        }

        // Eager-load catalogSync (steam_app_id / platforms) to avoid N+1 when callers
        // map the games into a payload that reads those fields.
        /** @var list<Game> $games */
        $games = $this->entityManager->createQueryBuilder()
            ->select('g', 'cs')
            ->from(Game::class, 'g')
            ->leftJoin('g.catalogSync', 'cs')
            ->where('g.availability IN (:availabilities)')
            ->setParameter('availabilities', $availabilities)
            // LOWER() so the order is case-insensitive: the DB collation is byte-ordered (C), which
            // otherwise sorts uppercase before lowercase (e.g. "ANIMAL WELL" before "ActRaiser").
            ->orderBy('LOWER(g.name)', 'ASC')
            ->getQuery()
            ->getResult();

        return $games;
    }

    public function findByApworldHash(string $sha256): ?Game
    {
        /* @var Game|null */
        return $this->entityManager->getRepository(Game::class)->findOneBy(['apworldHash' => $sha256]);
    }

    public function save(Game $game): void
    {
        $this->entityManager->persist($game);
        $sync = $game->getCatalogSync();
        if ($sync instanceof GameCatalogSync) {
            $this->entityManager->persist($sync);
        }
        $this->entityManager->flush();
    }

    public function remove(Game $game): void
    {
        $this->entityManager->remove($game);
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
