<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\RunListingReportQueryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalRunListingReportQuery implements RunListingReportQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function listed(string $runId): ?array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('r.id', 'r.title', 'r.pitch', 'r.owner_id')
            ->from('run', 'r')
            ->where('r.id = :id')
            ->andWhere("r.openness = 'members'")
            ->andWhere("r.status = 'draft'")
            ->andWhere('r.pitch IS NOT NULL')
            ->setParameter('id', $runId)
            ->executeQuery()
            ->fetchAllAssociative();

        return self::rows($rows)[$runId] ?? null;
    }

    public function byIds(array $runIds): array
    {
        if ([] === $runIds) {
            return [];
        }

        return self::rows($this->connection->createQueryBuilder()
            ->select('r.id', 'r.title', 'r.pitch', 'r.owner_id')
            ->from('run', 'r')
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $runIds, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative());
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array{runId: string, title: string, pitch: string|null, ownerId: string}>
     */
    private static function rows(array $rows): array
    {
        $runs = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $title = $row['title'] ?? null;
            $ownerId = $row['owner_id'] ?? null;
            if (!is_string($id) || !is_string($title) || !is_string($ownerId)) {
                continue;
            }
            $pitch = $row['pitch'] ?? null;
            $runs[$id] = ['runId' => $id, 'title' => $title, 'pitch' => is_string($pitch) ? $pitch : null, 'ownerId' => $ownerId];
        }

        return $runs;
    }
}
