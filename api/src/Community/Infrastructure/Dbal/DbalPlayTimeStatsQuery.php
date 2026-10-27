<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\PlayTimeStats;
use App\Community\Application\Query\PlayTimeStatsQueryInterface;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use Doctrine\DBAL\Connection;

/**
 * Sums the play spans of a member's slots (story 32.26).
 *
 * A span names its slot by session and slot name, the link DbalDeathLinkStatsQuery uses; each slot leads to its
 * owner and co-players through DbalSlotPlayerSource, so a co-played slot's time counts for each of its players.
 * A weekly attempt has no session slot and no span, and never counts.
 */
final readonly class DbalPlayTimeStatsQuery implements PlayTimeStatsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function forUser(string $userId): PlayTimeStats
    {
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $duration = 'EXTRACT(EPOCH FROM (p.last_seen_at - p.started_at))';
        $perSession = '(SELECT p.session_id, SUM('.$duration.') AS seconds, MAX('.$duration.') AS longest
                          FROM session_slot_play_span p
                          JOIN session_slot ss ON ss.session_id = p.session_id AND ss.slot_name = p.slot_name
                         WHERE EXISTS (SELECT 1 FROM '.$players.' sp
                                        WHERE sp.'.DbalSlotPlayerSource::SLOT_COLUMN.' = ss.id
                                          AND sp.'.DbalSlotPlayerSource::USER_COLUMN.' = :userId)
                         GROUP BY p.session_id)';

        $row = $this->connection->createQueryBuilder()
            ->select('COALESCE(SUM(t.seconds), 0) AS total', 'COALESCE(MAX(t.longest), 0) AS longest', 'COALESCE(MAX(t.seconds), 0) AS record')
            ->from($perSession, 't')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchAssociative();

        return new PlayTimeStats(
            self::seconds($row, 'total'),
            self::seconds($row, 'longest'),
            self::seconds($row, 'record'),
        );
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private static function seconds(array|false $row, string $key): int
    {
        $value = false === $row ? null : ($row[$key] ?? null);

        return is_numeric($value) ? max(0, (int) floor((float) $value)) : 0;
    }
}
