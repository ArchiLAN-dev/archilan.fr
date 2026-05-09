<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\ArchipelagoGame;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PublicGameCatalog
{
    public const PER_PAGE = 24;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function list(string $query = '', int $page = 1): array
    {
        $page = max(1, $page);

        $qb = $this->entityManager->createQueryBuilder()
            ->from(ArchipelagoGame::class, 'game')
            ->where('game.availability IN (:availabilities)')
            ->setParameter('availabilities', [
                ArchipelagoGame::AVAILABILITY_AVAILABLE,
                ArchipelagoGame::AVAILABILITY_EXPERIMENTAL,
            ])
            ->orderBy('game.name', 'ASC');

        if ('' !== $query) {
            $qb->andWhere('LOWER(game.name) LIKE :query OR LOWER(game.description) LIKE :query')
               ->setParameter('query', '%'.mb_strtolower($query).'%');
        }

        $total = (int) (clone $qb)->select('COUNT(game.id)')->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);

        /** @var list<ArchipelagoGame> $games */
        $games = $qb
            ->select('game')
            ->setFirstResult(($page - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE)
            ->getQuery()
            ->getResult();

        return [
            'items' => array_map(fn (ArchipelagoGame $game): array => [
                'id' => $game->getId(),
                'name' => $game->getName(),
                'slug' => $game->getSlug(),
                'description' => $game->getDescription(),
                'coverImageUrl' => $game->getCoverImageUrl(),
                'coverImageAlt' => $game->getCoverImageAlt(),
                'availability' => $game->getAvailability(),
            ], $games),
            'total' => $total,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'totalPages' => $totalPages,
        ];
    }
}
