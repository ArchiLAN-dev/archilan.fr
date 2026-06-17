<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure;

use App\PersonalRuns\Application\RecentlyPlayedGamesQueryInterface;
use App\PersonalRuns\Domain\Run;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalRecentlyPlayedGamesQuery implements RecentlyPlayedGamesQueryInterface
{
    private const RUN_TABLE = 'run';
    private const PARTICIPANT_TABLE = 'run_participant';

    public function __construct(private Connection $connection)
    {
    }

    public function recentlyPlayed(string $userId, string $excludeRunId, int $limit = 3): array
    {
        if ($limit <= 0) {
            return [];
        }

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('r.updated_at AS updated_at', 'r.title AS title', 'rp.game_slots AS game_slots')
            ->from(self::PARTICIPANT_TABLE, 'rp')
            ->innerJoin('rp', self::RUN_TABLE, 'r', $qb->expr()->eq('r.id', 'rp.personal_run_id'))
            ->where($qb->expr()->eq('rp.user_id', ':userId'))
            ->andWhere($qb->expr()->in('r.status', ':statuses'))
            ->andWhere($qb->expr()->neq('r.id', ':excludeRunId'))
            ->setParameter('userId', $userId)
            ->setParameter('excludeRunId', $excludeRunId)
            ->setParameter('statuses', Run::LAUNCHED_STATUSES, ArrayParameterType::STRING)
            ->orderBy('r.updated_at', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            $title = is_string($row['title'] ?? null) ? $row['title'] : '';
            $lastPlayedAt = $this->toIso8601($row['updated_at'] ?? null);

            foreach ($this->decodeGameIds($row['game_slots'] ?? null) as $gameId) {
                if (isset($seen[$gameId])) {
                    continue;
                }
                $seen[$gameId] = true;
                $result[] = [
                    'gameId' => $gameId,
                    'lastPlayedAt' => $lastPlayedAt,
                    'runTitle' => $title,
                ];

                if (count($result) >= $limit) {
                    return $result;
                }
            }
        }

        return $result;
    }

    /**
     * @return list<string> game ids in slot order
     */
    private function decodeGameIds(mixed $raw): array
    {
        if (!is_string($raw) || '' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $gameIds = [];
        foreach ($decoded as $slot) {
            if (is_array($slot) && is_string($slot['gameId'] ?? null) && '' !== $slot['gameId']) {
                $gameIds[] = $slot['gameId'];
            }
        }

        return $gameIds;
    }

    private function toIso8601(mixed $raw): string
    {
        if (!is_string($raw) || '' === $raw) {
            return '';
        }

        try {
            return (new \DateTimeImmutable($raw))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return '';
        }
    }
}
