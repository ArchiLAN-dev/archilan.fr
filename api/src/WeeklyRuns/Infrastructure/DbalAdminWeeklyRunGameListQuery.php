<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\WeeklyRuns\Application\AdminWeeklyRunGameListQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalAdminWeeklyRunGameListQuery implements AdminWeeklyRunGameListQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function execute(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(
                'g.id AS game_id',
                'g.name AS game_name',
                'g.cover_image_url',
                'g.cover_image_alt',
                'COUNT(DISTINCT wt.id) AS template_count',
                'COUNT(DISTINCT wr.id) AS run_count',
            )
            ->from('weekly_templates', 'wt')
            ->join('wt', 'game', 'g', 'g.id = wt.game_id')
            ->leftJoin('wt', 'weekly_runs', 'wr', 'wr.template_id = wt.id')
            ->groupBy('g.id', 'g.name', 'g.cover_image_url', 'g.cover_image_alt')
            // Case-insensitive: the byte-ordered (C) DB collation otherwise sorts uppercase first.
            ->orderBy('LOWER(g.name)', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'gameId' => is_string($row['game_id']) ? $row['game_id'] : '',
            'gameName' => is_string($row['game_name']) ? $row['game_name'] : '',
            'coverImageUrl' => is_string($row['cover_image_url'] ?? null) && '' !== $row['cover_image_url'] ? $row['cover_image_url'] : null,
            'coverImageAlt' => is_string($row['cover_image_alt'] ?? null) ? $row['cover_image_alt'] : '',
            'templateCount' => is_numeric($row['template_count']) ? (int) $row['template_count'] : 0,
            'runCount' => is_numeric($row['run_count']) ? (int) $row['run_count'] : 0,
        ], $rows);
    }
}
