<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\CommunityPresenceQueryInterface;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

/**
 * Reads "currently playing" presence from the live session tables (story 30.14), following the last check
 * (story 30.45).
 *
 * A member plays a slot when they own it or co-play it (story 16.17), in a running session, and the slot is
 * still live (no goal, not released) and **active**: its last check - or, before any check, the start of the
 * session - is less than ACTIVE_WINDOW old. A run still running on a game finished long ago no longer shows.
 *
 * Archipelago knows the slot, not the person: a check counts for everyone attached to the slot. The game shown
 * is therefore picked among the member's **own** active slots first, then the ones they co-play, and within
 * each group the most recently checked one wins - a member alternating between two games shows the one they
 * just checked, and a co-player busy on their own game is not pulled onto the owner's.
 */
final readonly class DbalCommunityPresenceQuery implements CommunityPresenceQueryInterface
{
    private const string RUNNING = 'running';
    private const string ACTIVE_WINDOW = '-30 minutes';

    private string $userTable;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
        $this->userTable = $connection->quoteSingleIdentifier('user');
    }

    public function playing(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        $qb = $this->activeSlots();
        $rows = $qb
            ->andWhere($qb->expr()->in('sp.'.DbalSlotPlayerSource::USER_COLUMN, ':ids'))
            ->setParameter('ids', $userIds, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $playing = [];
        foreach ($this->bestSlotPerUser($rows) as $userId => $row) {
            $playing[$userId] = ['sessionId' => $row['sessionId'], 'game' => $row['game']];
        }

        return $playing;
    }

    public function playingNow(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        // Restricted to listable members: the hub renders every row as a profile link, so a slug-less or deleted
        // account has nothing to point at.
        $qb = $this->activeSlots();
        $rows = $qb
            ->join('sp', $this->userTable, 'u', $qb->expr()->eq('u.id', 'sp.'.DbalSlotPlayerSource::USER_COLUMN))
            ->andWhere('u.slug IS NOT NULL')
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->executeQuery()
            ->fetchAllAssociative();

        $best = $this->bestSlotPerUser($rows);
        // Most recently active first; ties broken by user id so paging/caching stays deterministic.
        uasort($best, static fn (array $a, array $b): int => $b['activeAt'] <=> $a['activeAt'] ?: strcmp($a['userId'], $b['userId']));

        $result = [];
        foreach (array_slice(array_values($best), 0, $limit) as $row) {
            $result[] = ['userId' => $row['userId'], 'sessionId' => $row['sessionId'], 'game' => $row['game']];
        }

        return $result;
    }

    /**
     * Every active (member, slot) pair, owner or co-player, with whether the member owns the slot.
     */
    private function activeSlots(): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder();
        $user = 'sp.'.DbalSlotPlayerSource::USER_COLUMN;
        $activeAt = 'COALESCE(slot.last_check_at, s.started_at)';

        return $qb
            ->select(
                $user.' AS user_id',
                's.id AS session_id',
                'g.name AS game',
                'CASE WHEN '.$user.' = COALESCE(reg.user_id, slot.registration_id) THEN 1 ELSE 0 END AS owned',
                $activeAt.' AS active_at',
            )
            ->from('session_slot', 'slot')
            ->join('slot', DbalSlotPlayerSource::expression('session_slot', 'registration'), 'sp', $qb->expr()->eq('sp.'.DbalSlotPlayerSource::SLOT_COLUMN, 'slot.id'))
            ->join('slot', 'session', 's', $qb->expr()->eq('s.id', 'slot.session_id'))
            ->leftJoin('slot', 'registration', 'reg', $qb->expr()->eq('reg.id', 'slot.registration_id'))
            ->leftJoin('slot', 'game', 'g', $qb->expr()->eq('g.id', 'slot.game_id'))
            ->where($qb->expr()->eq('s.status', ':status'))
            ->andWhere($qb->expr()->isNull('slot.goal_reached_at'))
            ->andWhere('slot.was_released = false')
            ->andWhere($activeAt.' >= :since')
            ->setParameter('status', self::RUNNING)
            ->setParameter('since', $this->clock->now()->modify(self::ACTIVE_WINDOW), Types::DATETIMETZ_IMMUTABLE);
    }

    /**
     * The slot shown for each member: own slots before co-played ones, then the most recent activity.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array{userId: string, sessionId: string, game: string|null, owned: bool, activeAt: int}>
     */
    private function bestSlotPerUser(array $rows): array
    {
        $best = [];
        foreach ($rows as $row) {
            $userId = $row['user_id'] ?? null;
            $sessionId = $row['session_id'] ?? null;
            $activeAtRaw = $row['active_at'] ?? null;
            if (!is_string($userId) || !is_string($sessionId) || !is_string($activeAtRaw)) {
                continue;
            }
            $game = $row['game'] ?? null;
            $candidate = [
                'userId' => $userId,
                'sessionId' => $sessionId,
                'game' => is_string($game) ? $game : null,
                'owned' => in_array($row['owned'] ?? null, [1, '1', true], true),
                'activeAt' => new \DateTimeImmutable($activeAtRaw)->getTimestamp(),
            ];
            $current = $best[$userId] ?? null;
            if (null === $current
                || ($candidate['owned'] && !$current['owned'])
                || ($candidate['owned'] === $current['owned'] && $candidate['activeAt'] > $current['activeAt'])) {
                $best[$userId] = $candidate;
            }
        }

        return $best;
    }
}
