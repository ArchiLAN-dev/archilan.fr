<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

use App\GameSelection\Application\AdminGameListQueryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class DbalAdminGameListQuery implements AdminGameListQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(int $page, int $perPage, string $search, ?string $availability, ?bool $yamlReady, ?bool $apworldReady = null, string $sort = 'name', string $dir = 'asc'): array
    {
        $countQb = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('game', 'g');
        $this->applyFilters($countQb, $search, $availability, $yamlReady, $apworldReady);

        $countResult = $countQb->executeQuery()->fetchOne();
        $total = false !== $countResult && is_numeric($countResult) ? (int) $countResult : 0;

        $dataQb = $this->connection->createQueryBuilder()
            ->select(
                'g.id',
                'g.name',
                'g.slug',
                'g.description',
                'g.cover_image_url',
                'g.cover_image_alt',
                'g.cover_image_credit',
                'g.availability',
                'g.archipelago_game_name',
                'g.apworld_storage_key',
                'g.apworld_hash',
                'g.apworld_uploaded_at',
                'g.created_at',
                'g.updated_at',
                '(SELECT COUNT(*) FROM session_slot ss WHERE ss.game_id = g.id) '
                .'+ (SELECT COUNT(*) FROM weekly_templates wt WHERE wt.game_id = g.id) AS usage_count',
            )
            ->from('game', 'g')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        // LOWER() so the name order is case-insensitive: the byte-ordered (C) DB collation otherwise
        // sorts uppercase before lowercase (e.g. "ANIMAL WELL" before "ActRaiser").
        $direction = 'desc' === strtolower($dir) ? 'DESC' : 'ASC';
        if ('usage' === $sort) {
            $dataQb->orderBy('usage_count', $direction)->addOrderBy('LOWER(g.name)', 'ASC');
        } else {
            $dataQb->orderBy('LOWER(g.name)', $direction);
        }
        $this->applyFilters($dataQb, $search, $availability, $yamlReady, $apworldReady);

        $rows = $dataQb->executeQuery()->fetchAllAssociative();
        $items = array_map(fn (array $row): array => $this->mapRow($row), $rows);

        return ['items' => $items, 'total' => $total];
    }

    private function applyFilters(QueryBuilder $qb, string $search, ?string $availability, ?bool $yamlReady, ?bool $apworldReady): void
    {
        if ('' !== $search) {
            $qb->andWhere('(g.name ILIKE :search OR g.slug ILIKE :search)')
                ->setParameter('search', '%'.$search.'%');
        }

        if (null !== $availability) {
            $qb->andWhere('g.availability = :availability')
                ->setParameter('availability', $availability);
        }

        if (true === $yamlReady) {
            $qb->andWhere("g.archipelago_game_name IS NOT NULL AND g.archipelago_game_name <> ''");
        } elseif (false === $yamlReady) {
            $qb->andWhere("(g.archipelago_game_name IS NULL OR g.archipelago_game_name = '')");
        }

        if (true === $apworldReady) {
            $qb->andWhere("g.apworld_storage_key IS NOT NULL AND g.apworld_storage_key <> ''");
        } elseif (false === $apworldReady) {
            $qb->andWhere("(g.apworld_storage_key IS NULL OR g.apworld_storage_key = '')");
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $coverImageUrl = is_string($row['cover_image_url'] ?? null) && '' !== $row['cover_image_url'] ? $row['cover_image_url'] : null;
        $archipelagoGameName = is_string($row['archipelago_game_name'] ?? null) && '' !== $row['archipelago_game_name'] ? $row['archipelago_game_name'] : null;
        $apworldStorageKey = is_string($row['apworld_storage_key'] ?? null) && '' !== $row['apworld_storage_key'] ? $row['apworld_storage_key'] : null;
        $apworldHash = is_string($row['apworld_hash'] ?? null) && '' !== $row['apworld_hash'] ? $row['apworld_hash'] : null;

        return [
            'id' => is_string($row['id'] ?? null) ? $row['id'] : '',
            'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
            'slug' => is_string($row['slug'] ?? null) ? $row['slug'] : '',
            'description' => is_string($row['description'] ?? null) ? $row['description'] : '',
            'coverImageUrl' => $coverImageUrl,
            'coverImageAlt' => is_string($row['cover_image_alt'] ?? null) ? $row['cover_image_alt'] : '',
            'coverImageCredit' => is_string($row['cover_image_credit'] ?? null) ? $row['cover_image_credit'] : '',
            'availability' => is_string($row['availability'] ?? null) ? $row['availability'] : '',
            'archipelagoGameName' => $archipelagoGameName,
            'isYamlReady' => null !== $archipelagoGameName,
            'isApworldReady' => null !== $apworldStorageKey,
            'apworldHash' => $apworldHash,
            'apworldUploadedAt' => $this->formatDate($row['apworld_uploaded_at'] ?? null),
            'usageCount' => is_numeric($row['usage_count'] ?? null) ? (int) $row['usage_count'] : 0,
            'createdAt' => $this->formatDate($row['created_at'] ?? null) ?? '',
            'updatedAt' => $this->formatDate($row['updated_at'] ?? null) ?? '',
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
