<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\DeathLinkStats;
use App\Community\Application\Query\DeathLinkStatsQueryInterface;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use Doctrine\DBAL\Connection;

/**
 * Counts the DeathLink deaths of the session feed sent from a member's slots (story 32.25).
 *
 * The feed names the one who died as its sender, by slot name, the link DbalItemsFromOthersQuery uses; each slot
 * leads to its owner and co-players through DbalSlotPlayerSource, so a co-played slot's death counts for each of
 * its players. A death whose source named no slot (an older bridge, the admin's manual DeathLink) matches no slot
 * and counts for no one; a weekly attempt has no session slot and never counts.
 */
final readonly class DbalDeathLinkStatsQuery implements DeathLinkStatsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function forUser(string $userId): DeathLinkStats
    {
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $deaths = '(SELECT f.session_id, COUNT(*) AS deaths, SUM(COALESCE(json_array_length(f.victim_names), 0)) AS victims
                      FROM session_feed_event f
                      JOIN session_slot ss ON ss.session_id = f.session_id AND ss.slot_name = f.sender_name
                     WHERE f.type = :deathType
                       AND EXISTS (SELECT 1 FROM '.$players.' sp
                                    WHERE sp.'.DbalSlotPlayerSource::SLOT_COLUMN.' = ss.id
                                      AND sp.'.DbalSlotPlayerSource::USER_COLUMN.' = :userId)
                     GROUP BY f.session_id)';

        $row = $this->connection->createQueryBuilder()
            ->select('COALESCE(SUM(d.deaths), 0) AS deaths', 'COALESCE(SUM(d.victims), 0) AS victims', 'COALESCE(MAX(d.deaths), 0) AS record')
            ->from($deaths, 'd')
            ->setParameter('deathType', 'death_link')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchAssociative();

        return new DeathLinkStats(
            self::int($row, 'deaths'),
            self::int($row, 'victims'),
            self::int($row, 'record'),
        );
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private static function int(array|false $row, string $key): int
    {
        $value = false === $row ? null : filter_var($row[$key] ?? null, \FILTER_VALIDATE_INT);

        return \is_int($value) ? $value : 0;
    }
}
