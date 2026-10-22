<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure\Doctrine;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Entity\GameCatalogSync;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
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

    public function findAllIdsSortedByName(): array
    {
        $ids = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('id')
            ->from('game')
            ->orderBy('name', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_filter($ids, is_string(...)));
    }

    public function release(Game $game): void
    {
        // Cascades to the introspection and the catalog sync (mapping): a catalog sync left managed would point
        // at a detached game and fail the next flush.
        $this->entityManager->detach($game);
        // A game and its catalog sync point at each other, so a released game is a reference cycle that PHP
        // only frees when its cycle collector runs - after thousands of roots, never during a 900-game sweep,
        // which then grew by ~13 MB per 100 games anyway. Collected here, the sweep stays flat.
        gc_collect_cycles();
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
