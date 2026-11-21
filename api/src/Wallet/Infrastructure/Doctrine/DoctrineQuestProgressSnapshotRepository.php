<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Doctrine;

use App\Wallet\Domain\Repository\QuestProgressSnapshotRepositoryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DoctrineQuestProgressSnapshotRepository implements QuestProgressSnapshotRepositoryInterface
{
    private const int CHUNK = 500;

    public function __construct(private Connection $connection)
    {
    }

    #[\Override]
    public function record(string $weekKey, array $counts, \DateTimeImmutable $countedAt): void
    {
        $this->connection->transactional(function () use ($weekKey, $counts, $countedAt): void {
            if ([] !== $counts) {
                $this->connection->executeStatement(
                    'DELETE FROM quest_progress_count WHERE week_key = :week AND objective_key IN (:objectives)',
                    ['week' => $weekKey, 'objectives' => array_map(strval(...), array_keys($counts))],
                    ['objectives' => ArrayParameterType::STRING],
                );
            }
            $rows = [];
            foreach ($counts as $objectiveKey => $byMember) {
                foreach ($byMember as $userId => $count) {
                    if ($count > 0) {
                        $rows[] = [$objectiveKey, $userId, $count];
                    }
                }
            }
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                $values = [];
                $params = [];
                foreach ($chunk as $index => [$objectiveKey, $userId, $count]) {
                    $values[] = sprintf('(:week, :o%1$d, :u%1$d, :c%1$d)', $index);
                    $params['o'.$index] = $objectiveKey;
                    $params['u'.$index] = $userId;
                    $params['c'.$index] = $count;
                }
                $this->connection->executeStatement(
                    'INSERT INTO quest_progress_count (week_key, objective_key, user_id, count) VALUES '.implode(', ', $values),
                    ['week' => $weekKey] + $params,
                );
            }
            $this->connection->executeStatement(
                'INSERT INTO quest_progress_run (week_key, counted_at) VALUES (:week, :at)
                 ON CONFLICT (week_key) DO UPDATE SET counted_at = EXCLUDED.counted_at',
                ['week' => $weekKey, 'at' => $countedAt->format(\DATE_ATOM)],
            );
        });
    }

    #[\Override]
    public function of(string $weekKey, string $userId): ?array
    {
        if (false === $this->connection->fetchOne('SELECT 1 FROM quest_progress_run WHERE week_key = :week', ['week' => $weekKey])) {
            return null;
        }
        $counts = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT objective_key, count FROM quest_progress_count WHERE week_key = :week AND user_id = :user',
            ['week' => $weekKey, 'user' => $userId],
        ) as $row) {
            $key = $row['objective_key'] ?? null;
            $count = filter_var($row['count'] ?? null, \FILTER_VALIDATE_INT);
            if (is_string($key) && false !== $count) {
                $counts[$key] = $count;
            }
        }

        return $counts;
    }
}
