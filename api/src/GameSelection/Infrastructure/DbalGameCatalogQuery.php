<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

use App\GameSelection\Application\GameCatalogQueryInterface;
use App\GameSelection\Domain\Game;
use App\Shared\Application\PaginationHelper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class DbalGameCatalogQuery implements GameCatalogQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function list(string $query = '', int $page = 1): array
    {
        $page = max(1, $page);

        $countResult = $this->buildBaseQuery($query)->select('COUNT(game.id)')->fetchOne();
        $total = is_numeric($countResult) ? (int) $countResult : 0;

        $totalPages = max(1, (int) ceil($total / 24));
        $page = min($page, $totalPages);

        $qb = $this->selectGames($this->buildBaseQuery($query));

        PaginationHelper::applyTo($qb, $page, 24);

        $items = array_values(array_map($this->mapRow(...), $qb->fetchAllAssociative()));

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => 24,
            'totalPages' => $totalPages,
        ];
    }

    public function all(string $query = ''): array
    {
        $qb = $this->selectGames($this->buildBaseQuery($query));

        return array_values(array_map($this->mapRow(...), $qb->fetchAllAssociative()));
    }

    private function selectGames(QueryBuilder $qb): QueryBuilder
    {
        return $qb
            ->select(
                'game.id AS id',
                'game.name AS name',
                'game.slug AS slug',
                'game.description AS description',
                'game.cover_image_url AS cover_image_url',
                'game.cover_image_alt AS cover_image_alt',
                'game.availability AS availability',
                'sync.steam_app_id AS steam_app_id',
            )
            ->leftJoin('game', 'game_catalog_sync', 'sync', 'sync.game_id = game.id')
            ->orderBy('game.name', 'ASC');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: string, name: string, slug: string, description: string, coverImageUrl: string|null, coverImageAlt: string, availability: string, steamAppId: int|null, supportedEventTypes: list<string>}
     */
    private function mapRow(array $row): array
    {
        $id = $row['id'] ?? null;
        $name = $row['name'] ?? null;
        $slug = $row['slug'] ?? null;
        $description = $row['description'] ?? null;
        $coverImageUrl = $row['cover_image_url'] ?? null;
        $coverImageAlt = $row['cover_image_alt'] ?? null;
        $availability = $row['availability'] ?? null;
        $steamAppId = $row['steam_app_id'] ?? null;

        return [
            'id' => is_string($id) ? $id : '',
            'name' => is_string($name) ? $name : '',
            'slug' => is_string($slug) ? $slug : '',
            'description' => is_string($description) ? $description : '',
            'coverImageUrl' => is_string($coverImageUrl) ? $coverImageUrl : null,
            'coverImageAlt' => is_string($coverImageAlt) ? $coverImageAlt : '',
            'availability' => is_string($availability) ? $availability : '',
            'steamAppId' => is_numeric($steamAppId) ? (int) $steamAppId : null,
            'supportedEventTypes' => [],
        ];
    }

    private function buildBaseQuery(string $searchQuery): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->from('game', 'game')
            ->where('game.availability IN (:available, :experimental)')
            ->setParameter('available', Game::AVAILABILITY_AVAILABLE)
            ->setParameter('experimental', Game::AVAILABILITY_EXPERIMENTAL);

        if ('' !== $searchQuery) {
            $qb->andWhere('(LOWER(game.name) LIKE :query OR LOWER(game.description) LIKE :query)')
                ->setParameter('query', '%'.mb_strtolower($searchQuery).'%');
        }

        return $qb;
    }
}
