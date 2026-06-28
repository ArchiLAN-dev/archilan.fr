<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

use App\GameSelection\Application\GameCatalogQueryInterface;
use App\GameSelection\Application\InstallStepsReader;
use App\GameSelection\Domain\ApworldUpdateStatus;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\PlatformCategory;
use App\Shared\Application\PaginationHelper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class DbalGameCatalogQuery implements GameCatalogQueryInterface
{
    public function __construct(
        private Connection $connection,
        private InstallStepsReader $installStepsReader,
    ) {
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

    public function bySlug(string $slug): ?array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select(
                'game.id AS id',
                'game.name AS name',
                'game.slug AS slug',
                'game.description AS description',
                'game.cover_image_url AS cover_image_url',
                'game.cover_image_alt AS cover_image_alt',
                'game.cover_image_credit AS cover_image_credit',
                'game.availability AS availability',
                'game.archipelago_game_name AS archipelago_game_name',
                'game.option_types AS option_types',
                'game.install_steps AS install_steps',
                'sync.steam_app_id AS steam_app_id',
                'sync.platforms AS platforms',
                'sync.catalog_sheet_name AS catalog_sheet_name',
                'sync.apworld_source_url AS apworld_source_url',
                'sync.apworld_deployed_version AS apworld_deployed_version',
                'sync.apworld_latest_version AS apworld_latest_version',
                'sync.apworld_release_url AS apworld_release_url',
                'sync.apworld_checked_at AS apworld_checked_at',
            )
            ->from('game', 'game')
            ->leftJoin('game', 'game_catalog_sync', 'sync', 'sync.game_id = game.id')
            ->where('game.slug = :slug')
            ->andWhere('game.availability IN (:available, :experimental)')
            ->setParameter('slug', $slug)
            ->setParameter('available', Game::AVAILABILITY_AVAILABLE)
            ->setParameter('experimental', Game::AVAILABILITY_EXPERIMENTAL);

        $row = $qb->executeQuery()->fetchAssociative();
        if (false === $row) {
            return null;
        }

        return $this->mapDetailRow($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{
     *   id: string,
     *   name: string,
     *   slug: string,
     *   description: string,
     *   coverImageUrl: string|null,
     *   coverImageAlt: string,
     *   coverImageCredit: string,
     *   availability: string,
     *   steamAppId: int|null,
     *   platforms: list<string>,
     *   supportedEventTypes: list<string>,
     *   archipelagoGameName: string|null,
     *   catalogSheetName: string|null,
     *   apworld: array{deployedVersion: string|null, latestVersion: string|null, sourceUrl: string|null, releaseUrl: string|null, updateStatus: string},
     *   options: list<array{key: string, min: int, max: int, default: int|null}>,
     *   installSteps: list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>, imageKey: string|null, imageUrl: string|null, videoUrl: string|null}>
     * }
     */
    private function mapDetailRow(array $row): array
    {
        $id = $row['id'] ?? null;
        $name = $row['name'] ?? null;
        $slug = $row['slug'] ?? null;
        $description = $row['description'] ?? null;
        $coverImageUrl = $row['cover_image_url'] ?? null;
        $coverImageAlt = $row['cover_image_alt'] ?? null;
        $coverImageCredit = $row['cover_image_credit'] ?? null;
        $availability = $row['availability'] ?? null;
        $archipelagoGameName = $row['archipelago_game_name'] ?? null;
        $catalogSheetName = $row['catalog_sheet_name'] ?? null;
        $steamAppId = $row['steam_app_id'] ?? null;
        $sourceUrl = $row['apworld_source_url'] ?? null;
        $deployedVersion = $row['apworld_deployed_version'] ?? null;
        $latestVersion = $row['apworld_latest_version'] ?? null;
        $releaseUrl = $row['apworld_release_url'] ?? null;
        $checkedAtRaw = $row['apworld_checked_at'] ?? null;

        $checkedAt = is_string($checkedAtRaw) && '' !== $checkedAtRaw
            ? new \DateTimeImmutable($checkedAtRaw)
            : null;

        return [
            'id' => is_string($id) ? $id : '',
            'name' => is_string($name) ? $name : '',
            'slug' => is_string($slug) ? $slug : '',
            'description' => is_string($description) ? $description : '',
            'coverImageUrl' => is_string($coverImageUrl) ? $coverImageUrl : null,
            'coverImageAlt' => is_string($coverImageAlt) ? $coverImageAlt : '',
            'coverImageCredit' => is_string($coverImageCredit) ? $coverImageCredit : '',
            'availability' => is_string($availability) ? $availability : '',
            'steamAppId' => is_numeric($steamAppId) ? (int) $steamAppId : null,
            'platforms' => PlatformCategory::families(self::decodePlatforms($row['platforms'] ?? null)),
            'supportedEventTypes' => [],
            'archipelagoGameName' => is_string($archipelagoGameName) ? $archipelagoGameName : null,
            'catalogSheetName' => is_string($catalogSheetName) ? $catalogSheetName : null,
            'apworld' => [
                'deployedVersion' => is_string($deployedVersion) ? $deployedVersion : null,
                'latestVersion' => is_string($latestVersion) ? $latestVersion : null,
                'sourceUrl' => is_string($sourceUrl) ? $sourceUrl : null,
                'releaseUrl' => is_string($releaseUrl) ? $releaseUrl : null,
                'updateStatus' => ApworldUpdateStatus::compute(
                    is_string($sourceUrl) ? $sourceUrl : null,
                    $checkedAt,
                    is_string($latestVersion) ? $latestVersion : null,
                    is_string($deployedVersion) ? $deployedVersion : null,
                ),
            ],
            'options' => self::decodeOptions($row['option_types'] ?? null),
            'installSteps' => $this->installStepsReader->presentJson($row['install_steps'] ?? null),
        ];
    }

    /**
     * @return list<array{key: string, min: int, max: int, default: int|null}>
     */
    private static function decodeOptions(mixed $raw): array
    {
        if (!is_string($raw) || '' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $options = [];
        foreach ($decoded as $key => $spec) {
            if (!is_string($key) || !is_array($spec)) {
                continue;
            }

            $min = $spec['min'] ?? null;
            $max = $spec['max'] ?? null;
            $default = $spec['default'] ?? null;

            if (!is_int($min) || !is_int($max)) {
                continue;
            }

            $options[] = [
                'key' => $key,
                'min' => $min,
                'max' => $max,
                'default' => is_int($default) ? $default : null,
            ];
        }

        return $options;
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
                'sync.platforms AS platforms',
            )
            ->leftJoin('game', 'game_catalog_sync', 'sync', 'sync.game_id = game.id')
            // LOWER() so the order is case-insensitive: the DB collation is byte-ordered (C), which
            // otherwise sorts uppercase before lowercase (e.g. "ANIMAL WELL" before "ActRaiser").
            ->orderBy('LOWER(game.name)', 'ASC');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: string, name: string, slug: string, description: string, coverImageUrl: string|null, coverImageAlt: string, availability: string, steamAppId: int|null, platforms: list<string>, supportedEventTypes: list<string>}
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
            'platforms' => PlatformCategory::families(self::decodePlatforms($row['platforms'] ?? null)),
            'supportedEventTypes' => [],
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private static function decodePlatforms(mixed $raw): array
    {
        if (!is_string($raw) || '' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $platforms = [];
        foreach ($decoded as $platform) {
            if (!is_array($platform)) {
                continue;
            }
            $id = $platform['id'] ?? null;
            $name = $platform['name'] ?? null;
            if (is_int($id) && is_string($name) && '' !== $name) {
                $platforms[] = ['id' => $id, 'name' => $name];
            }
        }

        return $platforms;
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
