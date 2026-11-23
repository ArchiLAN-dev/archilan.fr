<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Dbal;

use App\Sessions\Application\Query\SlotForecastQueryInterface;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Story 56.8: the forecast and calibration reads. The feed is only ever read for one session, on its
 * `(session_id, occurred_at)` index (the v1.0.1 hotfix: an unbounded feed scan took the quests page down).
 */
final readonly class DbalSlotForecastQuery implements SlotForecastQueryInterface
{
    /** AP's progression flag on an item. */
    private const int PROGRESSION_FLAG = 1;

    /** Below a minute, a goal is a forfeit or a test, not a duration. */
    private const int MIN_SECONDS = 60;

    public function __construct(private Connection $connection)
    {
    }

    public function slotPlayers(string $sessionId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('ss.id', 'sp.'.DbalSlotPlayerSource::USER_COLUMN.' AS user_id')
            ->from('session_slot', 'ss')
            ->join('ss', DbalSlotPlayerSource::expression('session_slot', 'registration'), 'sp', 'sp.'.DbalSlotPlayerSource::SLOT_COLUMN.' = ss.id')
            ->where($qb->expr()->eq('ss.session_id', ':sessionId'))
            ->setParameter('sessionId', $sessionId)
            ->executeQuery()
            ->fetchAllAssociative();

        $players = [];
        foreach ($rows as $row) {
            $slotId = $row['id'] ?? null;
            $userId = $row['user_id'] ?? null;
            if (!is_string($slotId) || !is_string($userId) || '' === $userId) {
                continue;
            }
            $players[$slotId] ??= [];
            if (!in_array($userId, $players[$slotId], true)) {
                $players[$slotId][] = $userId;
            }
        }

        return $players;
    }

    public function runningSession(string $eventId): ?string
    {
        $qb = $this->connection->createQueryBuilder();
        $id = $qb
            ->select('s.id')
            ->from('session', 's')
            ->where($qb->expr()->eq('s.event_id', ':eventId'))
            ->andWhere($qb->expr()->eq('s.status', ':running'))
            ->orderBy('s.started_at', 'DESC')
            ->setMaxResults(1)
            ->setParameter('eventId', $eventId)
            ->setParameter('running', Session::STATUS_RUNNING)
            ->executeQuery()
            ->fetchOne();

        return is_string($id) ? $id : null;
    }

    public function liveSlots(string $sessionId, int $windowSeconds): array
    {
        $start = 'COALESCE(s.first_launched_at, s.started_at)';
        $windowStart = 'GREATEST(ss.last_check_at - make_interval(secs => :window), '.$start.')';
        $recent = 'SELECT COUNT(*) FROM session_feed_event f'
            .' WHERE f.session_id = ss.session_id AND f.type = :itemType AND f.sender_name = ss.slot_name'
            .' AND f.occurred_at > '.$windowStart.' AND f.occurred_at <= ss.last_check_at';
        $hostedSent = 'SELECT COUNT(*) FROM session_feed_event f'
            .' WHERE f.session_id = ss.session_id AND f.type = :itemType AND f.sender_name = ss.slot_name'
            .' AND f.receiver_name <> ss.slot_name AND (f.item_flags & :progression) <> 0';

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select(
                'ss.id', 'ss.slot_name', 'ss.game_id', 'g.name AS game_name', 'ss.checks_done', 'ss.locations_total',
                'ss.hosted_progression', 'ss.goal_reached_at', 'ss.was_released',
                '('.$recent.') AS recent_checks',
                'COALESCE(EXTRACT(EPOCH FROM (ss.last_check_at - '.$windowStart.')), 0) AS recent_seconds',
                '('.$hostedSent.') AS hosted_sent',
            )
            ->from('session_slot', 'ss')
            ->join('ss', 'session', 's', $qb->expr()->eq('s.id', 'ss.session_id'))
            ->leftJoin('ss', 'game', 'g', $qb->expr()->eq('g.id', 'ss.game_id'))
            ->where($qb->expr()->eq('ss.session_id', ':sessionId'))
            ->orderBy('ss.slot_order')
            ->setParameter('sessionId', $sessionId)
            ->setParameter('window', $windowSeconds)
            ->setParameter('itemType', SessionFeedEvent::TYPE_ITEM_RECEIVED)
            ->setParameter('progression', self::PROGRESSION_FLAG)
            ->executeQuery()
            ->fetchAllAssociative();

        $slots = [];
        foreach ($rows as $row) {
            $slotId = $row['id'] ?? null;
            $slotName = $row['slot_name'] ?? null;
            $gameId = $row['game_id'] ?? null;
            if (!is_string($slotId) || !is_string($slotName) || !is_string($gameId)) {
                continue;
            }
            $slots[] = [
                'slotId' => $slotId,
                'slotName' => $slotName,
                'gameId' => $gameId,
                'gameName' => is_string($row['game_name'] ?? null) ? $row['game_name'] : $gameId,
                'checksDone' => self::int($row['checks_done'] ?? null) ?? 0,
                'locations' => self::int($row['locations_total'] ?? null),
                'hostedProgression' => self::int($row['hosted_progression'] ?? null),
                'goalReached' => null !== ($row['goal_reached_at'] ?? null),
                'released' => true === ($row['was_released'] ?? null),
                'recentChecks' => self::int($row['recent_checks'] ?? null) ?? 0,
                'recentSeconds' => max(0, (int) round(self::float($row['recent_seconds'] ?? null) ?? 0.0)),
                'hostedSent' => self::int($row['hosted_sent'] ?? null) ?? 0,
            ];
        }

        return $slots;
    }

    public function eventCalibration(string $eventId): array
    {
        $qb = $this->compared();
        $rows = $qb
            ->addSelect('ss.slot_name', 'g.name AS game_name')
            ->leftJoin('ss', 'game', 'g', $qb->expr()->eq('g.id', 'ss.game_id'))
            ->andWhere($qb->expr()->eq('s.event_id', ':eventId'))
            ->orderBy('ss.goal_reached_at')
            ->setParameter('eventId', $eventId)
            ->executeQuery()
            ->fetchAllAssociative();

        $slots = [];
        foreach ($rows as $row) {
            $slotName = $row['slot_name'] ?? null;
            $estimated = self::float($row['estimated_hours'] ?? null);
            $seconds = self::float($row['seconds'] ?? null);
            if (!is_string($slotName) || null === $estimated || null === $seconds) {
                continue;
            }
            $slots[] = [
                'slotName' => $slotName,
                'gameName' => is_string($row['game_name'] ?? null) ? $row['game_name'] : '',
                'estimatedHours' => $estimated,
                'actualHours' => $seconds / 3600,
            ];
        }

        return $slots;
    }

    public function gameCalibration(string $gameId): ?array
    {
        $qb = $this->compared();
        $rows = $qb
            ->andWhere($qb->expr()->eq('ss.game_id', ':gameId'))
            ->setParameter('gameId', $gameId)
            ->executeQuery()
            ->fetchAllAssociative();

        $ratios = [];
        foreach ($rows as $row) {
            $estimated = self::float($row['estimated_hours'] ?? null);
            $seconds = self::float($row['seconds'] ?? null);
            if (null !== $estimated && null !== $seconds && $estimated > 0) {
                $ratios[] = ($seconds / 3600) / $estimated;
            }
        }

        return [] === $ratios ? null : ['slots' => count($ratios), 'meanRatio' => array_sum($ratios) / count($ratios)];
    }

    /** The slots that reached their goal with an expected duration, and the seconds they took. */
    private function compared(): QueryBuilder
    {
        $seconds = 'EXTRACT(EPOCH FROM (ss.goal_reached_at - COALESCE(s.first_launched_at, s.started_at)))';
        $qb = $this->connection->createQueryBuilder();

        return $qb
            ->select('ss.estimated_hours', $seconds.' AS seconds')
            ->from('session_slot', 'ss')
            ->join('ss', 'session', 's', $qb->expr()->eq('s.id', 'ss.session_id'))
            ->where('ss.estimated_hours IS NOT NULL')
            ->andWhere('ss.goal_reached_at IS NOT NULL')
            ->andWhere($seconds.' >= '.self::MIN_SECONDS);
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }

    private static function float(mixed $value): ?float
    {
        return is_float($value) || is_int($value) ? (float) $value : (is_numeric($value) ? (float) $value : null);
    }
}
