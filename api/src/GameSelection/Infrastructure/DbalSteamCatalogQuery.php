<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

use App\GameSelection\Application\SteamCatalogQueryInterface;
use App\GameSelection\Domain\Game;
use Doctrine\DBAL\Connection;

final readonly class DbalSteamCatalogQuery implements SteamCatalogQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function allWithSteamAppId(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(
                'game.id AS id',
                'game.name AS name',
                'game.slug AS slug',
                'game.cover_image_url AS cover_image_url',
                'game.availability AS availability',
                'sync.steam_app_id AS steam_app_id',
            )
            ->from('game', 'game')
            ->innerJoin('game', 'game_catalog_sync', 'sync', 'sync.game_id = game.id')
            ->where('sync.steam_app_id IS NOT NULL')
            ->andWhere('game.availability IN (:available, :experimental)')
            ->setParameter('available', Game::AVAILABILITY_AVAILABLE)
            ->setParameter('experimental', Game::AVAILABILITY_EXPERIMENTAL)
            ->orderBy('game.name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $items = [];
        foreach ($rows as $row) {
            $steamAppId = $row['steam_app_id'] ?? null;
            if (!is_numeric($steamAppId)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $name = $row['name'] ?? null;
            $slug = $row['slug'] ?? null;
            $coverImageUrl = $row['cover_image_url'] ?? null;
            $availability = $row['availability'] ?? null;

            $items[] = [
                'id' => is_string($id) ? $id : '',
                'name' => is_string($name) ? $name : '',
                'slug' => is_string($slug) ? $slug : '',
                'coverImageUrl' => is_string($coverImageUrl) ? $coverImageUrl : null,
                'availability' => is_string($availability) ? $availability : '',
                'steamAppId' => (int) $steamAppId,
            ];
        }

        return $items;
    }
}
