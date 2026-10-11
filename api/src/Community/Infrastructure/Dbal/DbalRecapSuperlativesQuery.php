<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\RecapSuperlativesQueryInterface;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads the session_recap projection cross-context by table name (the DbalEventParticipationQuery
 * precedent) so the achievement engine learns about recap superlatives without importing the
 * Sessions domain (story 32.4).
 *
 * Scope (story 32.23): finished sessions of a public event, through a submitted registration, and
 * finished sessions of a personal run. Weekly attempts have no recap superlatives to read. A
 * superlative is won by every member who plays the winning slot - its owner and its co-players,
 * through DbalSlotPlayerSource - and counts once per member, session and key.
 *
 * A session played by fewer than MIN_PLAYERS distinct members contributes nothing. The recap
 * builder no longer computes superlatives for such a session (RecapSuperlativesCalculator, whose
 * constant this one duplicates: Community does not import Sessions), but recaps built before story
 * 32.23 still carry them. The superlatives JSON is decoded in PHP; a malformed column contributes
 * nothing.
 */
final readonly class DbalRecapSuperlativesQuery implements RecapSuperlativesQueryInterface
{
    public const int MIN_PLAYERS = 2;

    public function __construct(private Connection $connection)
    {
    }

    public function superlativeCountsFor(string $userId): array
    {
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $playerCount = 'SELECT COUNT(DISTINCT pc.'.DbalSlotPlayerSource::USER_COLUMN.') FROM session_slot ps'
            .' JOIN '.$players.' pc ON pc.'.DbalSlotPlayerSource::SLOT_COLUMN.' = ps.id WHERE ps.session_id = s.id';

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('sr.session_id AS session_id', 'sr.superlatives AS superlatives', 'COALESCE(slot.slot_id, slot.id) AS slot_id')
            ->from('session_recap', 'sr')
            ->join('sr', 'session', 's', 's.id = sr.session_id')
            ->join('s', 'session_slot', 'slot', 'slot.session_id = s.id')
            ->join('slot', $players, 'sp', 'sp.'.DbalSlotPlayerSource::SLOT_COLUMN.' = slot.id')
            ->leftJoin('s', 'event', 'e', 'e.id = s.event_id')
            ->leftJoin('slot', 'registration', 'r', 'r.id = slot.registration_id AND r.event_id = s.event_id')
            ->leftJoin('s', 'run', 'run', 'run.id = s.event_id')
            ->where($qb->expr()->eq('sp.'.DbalSlotPlayerSource::USER_COLUMN, ':userId'))
            ->andWhere($qb->expr()->eq('s.status', ':finished'))
            ->andWhere('run.id IS NOT NULL OR (e.is_public = :public AND r.status = :reserved AND r.submitted_at IS NOT NULL)')
            ->andWhere('('.$playerCount.') >= :minPlayers')
            ->setParameter('userId', $userId)
            ->setParameter('finished', 'finished')
            ->setParameter('public', true, ParameterType::BOOLEAN)
            ->setParameter('reserved', 'reserved')
            ->setParameter('minPlayers', self::MIN_PLAYERS, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        /** @var array<string, true> $won "sessionId|key" */
        $won = [];
        foreach ($rows as $row) {
            $sessionId = $row['session_id'] ?? null;
            $slotId = $row['slot_id'] ?? null;
            $json = $row['superlatives'] ?? null;
            if (!is_string($sessionId) || !is_string($slotId) || !is_string($json)) {
                continue;
            }
            $superlatives = json_decode($json, true);
            if (!is_array($superlatives)) {
                continue;
            }
            foreach ($superlatives as $superlative) {
                if (!is_array($superlative)) {
                    continue;
                }
                $key = $superlative['key'] ?? null;
                $winnerSlotId = $superlative['slotId'] ?? null;
                if (!is_string($key) || $winnerSlotId !== $slotId) {
                    continue;
                }
                $won[$sessionId.'|'.$key] = true;
            }
        }

        $counts = [];
        foreach (array_keys($won) as $sessionAndKey) {
            $key = substr($sessionAndKey, (int) strpos($sessionAndKey, '|') + 1);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }
}
