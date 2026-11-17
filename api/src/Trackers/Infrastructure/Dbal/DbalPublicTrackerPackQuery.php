<?php

declare(strict_types=1);

namespace App\Trackers\Infrastructure\Dbal;

use App\Trackers\Application\Query\PublicTrackerPackQueryInterface;
use App\Trackers\Application\Query\PublicTrackerPackView;
use App\Trackers\Domain\ValueObject\TrackerPackVariant;
use Doctrine\DBAL\Connection;

final readonly class DbalPublicTrackerPackQuery implements PublicTrackerPackQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function enabledForGame(string $gameId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select(
                'p.id', 'p.name', 'p.author', 'p.license_spdx', 'p.source_url', 'p.is_default', 'p.default_variant_uid',
                'v.id AS version_id', 'v.package_version', 'v.root_path', 'v.size_bytes', 'v.sha256', 'v.variants',
            )
            ->from('tracker_pack', 'p')
            ->innerJoin('p', 'tracker_pack_version', 'v', $qb->expr()->eq('v.id', 'p.current_version_id'))
            ->where($qb->expr()->eq('p.game_id', ':gameId'))
            ->andWhere($qb->expr()->eq('p.enabled', ':enabled'))
            ->setParameter('gameId', $gameId)
            ->setParameter('enabled', true, 'boolean')
            ->orderBy('p.is_default', 'DESC')
            ->addOrderBy('p.created_at', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $views = [];
        foreach ($rows as $row) {
            $variants = TrackerPackVariantRows::decode($row['variants']);
            $views[] = new PublicTrackerPackView(
                is_string($row['id']) ? $row['id'] : '',
                is_string($row['name']) ? $row['name'] : '',
                is_string($row['author']) ? $row['author'] : null,
                is_string($row['license_spdx']) ? $row['license_spdx'] : null,
                is_string($row['source_url']) ? $row['source_url'] : '',
                true === $row['is_default'],
                [] !== array_filter($variants, static fn (array $v): bool => in_array(TrackerPackVariant::ARCHIPELAGO_FLAG, $v['flags'], true)),
                is_string($row['default_variant_uid']) ? $row['default_variant_uid'] : null,
                [
                    'id' => is_string($row['version_id']) ? $row['version_id'] : '',
                    'packageVersion' => is_string($row['package_version']) ? $row['package_version'] : null,
                    'rootPath' => is_string($row['root_path']) ? $row['root_path'] : '',
                    'sizeBytes' => is_numeric($row['size_bytes']) ? (int) $row['size_bytes'] : 0,
                    'sha256' => is_string($row['sha256']) ? $row['sha256'] : '',
                    'variants' => $variants,
                ],
            );
        }

        return $views;
    }
}
