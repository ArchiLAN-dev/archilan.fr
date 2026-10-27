<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\GameActivityQueryInterface;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use Doctrine\DBAL\Connection;

/**
 * Connected time (story 32.26) and DeathLink deaths (story 32.25) of a member's slots, by game (story 30.55).
 *
 * Spans and deaths name their slot by session and slot name, the link DbalPlayTimeStatsQuery and
 * DbalDeathLinkStatsQuery use; the slot gives the game and, through DbalSlotPlayerSource, its players.
 */
final readonly class DbalGameActivityQuery implements GameActivityQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function forUser(string $userId): array
    {
        $out = [];
        foreach ($this->rows($userId) as $row) {
            $gameId = $row['game_id'] ?? null;
            if (!\is_string($gameId) || '' === $gameId) {
                continue;
            }
            $out[$gameId] = [
                'playedSeconds' => self::int($row, 'seconds'),
                'deaths' => self::int($row, 'deaths'),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $userId): array
    {
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $mine = 'EXISTS (SELECT 1 FROM '.$players.' sp
                          WHERE sp.'.DbalSlotPlayerSource::SLOT_COLUMN.' = ss.id
                            AND sp.'.DbalSlotPlayerSource::USER_COLUMN.' = :userId)';
        $seconds = '(SELECT COALESCE(SUM(EXTRACT(EPOCH FROM (p.last_seen_at - p.started_at))), 0)
                       FROM session_slot_play_span p
                      WHERE p.session_id = ss.session_id AND p.slot_name = ss.slot_name)';
        $deaths = '(SELECT COUNT(*) FROM session_feed_event f
                     WHERE f.session_id = ss.session_id AND f.sender_name = ss.slot_name AND f.type = :deathType)';

        return $this->connection->createQueryBuilder()
            ->select('ss.game_id AS game_id', 'SUM('.$seconds.') AS seconds', 'SUM('.$deaths.') AS deaths')
            ->from('session_slot', 'ss')
            ->where($mine)
            ->groupBy('ss.game_id')
            ->setParameter('userId', $userId)
            ->setParameter('deathType', 'death_link')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? max(0, (int) floor((float) $value)) : 0;
    }
}
