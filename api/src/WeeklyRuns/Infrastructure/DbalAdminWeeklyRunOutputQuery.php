<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\WeeklyRuns\Application\AdminWeeklyRunOutputQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalAdminWeeklyRunOutputQuery implements AdminWeeklyRunOutputQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function findOutputKey(string $weeklyRunId): ?string
    {
        $key = $this->connection->createQueryBuilder()
            ->select('wr.generated_output_key')
            ->from('weekly_runs', 'wr')
            ->where('wr.id = :id')
            ->setParameter('id', $weeklyRunId)
            ->executeQuery()
            ->fetchOne();

        return is_string($key) && '' !== $key ? $key : null;
    }
}
