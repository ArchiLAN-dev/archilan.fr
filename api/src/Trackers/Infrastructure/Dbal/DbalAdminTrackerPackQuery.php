<?php

declare(strict_types=1);

namespace App\Trackers\Infrastructure\Dbal;

use App\Trackers\Application\Query\AdminTrackerPackQueryInterface;
use App\Trackers\Application\Query\AdminTrackerPackView;
use App\Trackers\Domain\ValueObject\TrackerPackVariant;
use Doctrine\DBAL\Connection;

final readonly class DbalAdminTrackerPackQuery implements AdminTrackerPackQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function forGame(string $gameId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select(
                'p.id', 'p.name', 'p.author', 'p.package_uid', 'p.game_name', 'p.source_url', 'p.license_spdx',
                'p.enabled', 'p.is_default', 'p.default_variant_uid', 'p.license_acknowledged',
                'v.id AS version_id', 'v.package_version', 'v.git_tag', 'v.size_bytes', 'v.variants',
                'v.min_poptracker_version', 'v.imported_at',
            )
            ->from('tracker_pack', 'p')
            ->innerJoin('p', 'tracker_pack_version', 'v', $qb->expr()->eq('v.id', 'p.current_version_id'))
            ->where($qb->expr()->eq('p.game_id', ':gameId'))
            ->setParameter('gameId', $gameId)
            ->orderBy('p.created_at', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $views = [];
        foreach ($rows as $row) {
            $variants = TrackerPackVariantRows::decode($row['variants']);
            $views[] = new AdminTrackerPackView(
                self::text($row['id']),
                self::text($row['name']),
                self::nullableText($row['author']),
                self::text($row['package_uid']),
                self::nullableText($row['game_name']),
                self::text($row['source_url']),
                self::nullableText($row['license_spdx']),
                [] !== array_filter($variants, static fn (array $v): bool => in_array(TrackerPackVariant::ARCHIPELAGO_FLAG, $v['flags'], true)),
                self::text($row['version_id']),
                self::nullableText($row['package_version']),
                self::nullableText($row['git_tag']),
                is_numeric($row['size_bytes']) ? (int) $row['size_bytes'] : 0,
                $variants,
                self::nullableText($row['min_poptracker_version']),
                new \DateTimeImmutable(self::text($row['imported_at']))->format(\DATE_ATOM),
                true === $row['enabled'],
                true === $row['is_default'],
                self::nullableText($row['default_variant_uid']),
                true === $row['license_acknowledged'],
            );
        }

        return $views;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function nullableText(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
