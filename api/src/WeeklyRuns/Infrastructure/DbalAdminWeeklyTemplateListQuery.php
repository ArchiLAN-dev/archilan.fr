<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\WeeklyRuns\Application\AdminWeeklyTemplateListQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalAdminWeeklyTemplateListQuery implements AdminWeeklyTemplateListQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function execute(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(
                'wt.id',
                'wt.name',
                'wt.game_id',
                'g.name AS game_name',
                'wt.max_attempts',
                'wt.is_active',
                'wt.created_at',
            )
            ->from('weekly_templates', 'wt')
            ->leftJoin('wt', 'game', 'g', 'g.id = wt.game_id')
            ->orderBy('wt.created_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $data = array_map(static fn (array $row): array => [
            'id' => is_string($row['id']) ? $row['id'] : '',
            'name' => is_string($row['name']) ? $row['name'] : null,
            'gameId' => is_string($row['game_id']) ? $row['game_id'] : '',
            'gameName' => is_string($row['game_name']) ? $row['game_name'] : '',
            'maxAttempts' => is_numeric($row['max_attempts']) ? (int) $row['max_attempts'] : null,
            'isActive' => (bool) $row['is_active'],
            'createdAt' => is_string($row['created_at']) ? $row['created_at'] : '',
        ], $rows);

        return ['data' => $data, 'meta' => ['total' => count($data)]];
    }
}
