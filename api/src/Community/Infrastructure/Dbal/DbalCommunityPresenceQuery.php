<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\LivePresenceQueryInterface;
use App\Community\Domain\Enum\PresenceVisibility;
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
 *
 * Story 43.6: each row carries what the caller needs to apply the member's presence visibility (their setting, and
 * whether the viewer is a friend). A block either way with the viewer removes the row here, whatever the setting.
 */
final readonly class DbalCommunityPresenceQuery implements LivePresenceQueryInterface
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

    public function playing(array $userIds, ?string $viewerId): array
    {
        if ([] === $userIds) {
            return [];
        }

        $qb = $this->activeSlots($viewerId);
        $rows = $qb
            ->andWhere($qb->expr()->in('sp.'.DbalSlotPlayerSource::USER_COLUMN, ':ids'))
            ->setParameter('ids', $userIds, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $playing = [];
        foreach ($this->bestSlotPerUser($rows) as $userId => $row) {
            $playing[$userId] = [
                'sessionId' => $row['sessionId'],
                'game' => $row['game'],
                'slotName' => $row['slotName'],
                'goalReached' => $row['goalReached'],
                'tracked' => $row['tracked'],
                'visibility' => $row['visibility'],
                'friend' => $row['friend'],
            ];
        }

        return $playing;
    }

    public function recentlyFinished(array $userIds, ?string $viewerId, \DateTimeImmutable $since): array
    {
        if ([] === $userIds) {
            return [];
        }

        $qb = $this->finishedSlots($viewerId, $since);
        $rows = $qb
            ->andWhere($qb->expr()->in('sp.'.DbalSlotPlayerSource::USER_COLUMN, ':ids'))
            ->setParameter('ids', $userIds, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $finished = [];
        // Story 43.19: the latest session played, whatever slot it was.
        foreach ($this->bestSlotPerUser($rows, latestFirst: true) as $userId => $row) {
            $finished[$userId] = [
                'sessionId' => $row['sessionId'],
                'game' => $row['game'],
                'finishedAt' => new \DateTimeImmutable('@'.$row['activeAt'])->format(\DateTimeInterface::ATOM),
                'visibility' => $row['visibility'],
                'friend' => $row['friend'],
            ];
        }

        return $finished;
    }

    public function snapshotSlots(array $sessionIds): array
    {
        if ([] === $sessionIds) {
            return [];
        }

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('snap.session_id', 'snap.payload')
            ->from('session_players_snapshot', 'snap')
            ->where($qb->expr()->in('snap.session_id', ':ids'))
            ->setParameter('ids', $sessionIds, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $bySession = [];
        foreach ($rows as $row) {
            $sessionId = $row['session_id'] ?? null;
            $payload = is_string($row['payload'] ?? null) ? json_decode($row['payload'], true) : null;
            $slots = is_array($payload) ? ($payload['slots'] ?? null) : null;
            if (!is_string($sessionId) || !is_array($slots)) {
                continue;
            }
            foreach ($slots as $slot) {
                $slotName = is_array($slot) ? ($slot['slot_name'] ?? null) : null;
                if (is_array($slot) && is_string($slotName)) {
                    $bySession[$sessionId][$slotName] = $slot;
                }
            }
        }

        return $bySession;
    }

    public function playingNow(?string $viewerId): array
    {
        // Restricted to listable members: the hub renders every row as a profile link, so a slug-less or deleted
        // account has nothing to point at.
        $qb = $this->activeSlots($viewerId);
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
        foreach ($best as $row) {
            $result[] = ['userId' => $row['userId'], 'sessionId' => $row['sessionId'], 'game' => $row['game'], 'visibility' => $row['visibility'], 'friend' => $row['friend']];
        }

        return $result;
    }

    /**
     * Every active (member, slot) pair, owner or co-player, in a running session: checked in the window, or whose
     * goal was reached in it.
     */
    private function activeSlots(?string $viewerId): QueryBuilder
    {
        $activeAt = 'COALESCE(slot.last_check_at, s.started_at)';
        $qb = $this->playerSlots($viewerId, $activeAt);

        return $qb
            ->andWhere($qb->expr()->eq('s.status', ':status'))
            ->andWhere('slot.was_released = false')
            // Story 43.7: a goal reached within the window keeps the member shown, « Objectif atteint ».
            ->andWhere('(slot.goal_reached_at IS NULL AND '.$activeAt.' >= :since) OR slot.goal_reached_at >= :since')
            ->setParameter('status', self::RUNNING)
            ->setParameter('since', $this->clock->now()->modify(self::ACTIVE_WINDOW), Types::DATETIMETZ_IMMUTABLE);
    }

    /**
     * Every (member, slot) pair of a session no longer running that was played since the given time (story 43.5):
     * its activity is the slot's last check, or the session's end, whichever is latest. Story 43.19: a
     * session paused or stopped on idle counts as much as a finished one - a run of several weeks is rarely finished.
     */
    private function finishedSlots(?string $viewerId, \DateTimeImmutable $since): QueryBuilder
    {
        // Not the stop itself: a crash or a failed launch is no sign that anyone played.
        $activeAt = 'GREATEST(slot.last_check_at, s.finished_at)';
        $qb = $this->playerSlots($viewerId, $activeAt);

        return $qb
            ->andWhere($qb->expr()->neq('s.status', ':status'))
            ->andWhere($activeAt.' >= :since')
            ->setParameter('status', self::RUNNING)
            ->setParameter('since', $since, Types::DATETIMETZ_IMMUTABLE);
    }

    /**
     * The (member, slot) pairs, owner or co-player, with whether the member owns the slot, their presence visibility
     * and whether the viewer is their friend. A member blocked either way with the viewer is left out.
     */
    private function playerSlots(?string $viewerId, string $activeAt): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder();
        $user = 'sp.'.DbalSlotPlayerSource::USER_COLUMN;
        $friend = null === $viewerId ? '0' : "CASE WHEN EXISTS (SELECT 1 FROM community_friendship f WHERE f.status = 'accepted'"
            .' AND ((f.requester_id = :viewer AND f.addressee_id = '.$user.') OR (f.addressee_id = :viewer AND f.requester_id = '.$user.'))) THEN 1 ELSE 0 END';

        $qb
            ->select(
                $user.' AS user_id',
                's.id AS session_id',
                'g.name AS game',
                'CASE WHEN '.$user.' = COALESCE(reg.user_id, slot.registration_id) THEN 1 ELSE 0 END AS owned',
                $activeAt.' AS active_at',
                'COALESCE(cp.presence_visibility, :defaultVisibility) AS visibility',
                $friend.' AS friend',
                'slot.slot_name AS slot_name',
                'CASE WHEN slot.goal_reached_at IS NULL THEN 0 ELSE 1 END AS goal_reached',
                // An imported seed has no detailed tracking (story 43.7): its presence shows the game only.
                'CASE WHEN run.imported_output_key IS NULL THEN 1 ELSE 0 END AS tracked',
            )
            ->from('session_slot', 'slot')
            ->join('slot', DbalSlotPlayerSource::expression('session_slot', 'registration'), 'sp', $qb->expr()->eq('sp.'.DbalSlotPlayerSource::SLOT_COLUMN, 'slot.id'))
            ->join('slot', 'session', 's', $qb->expr()->eq('s.id', 'slot.session_id'))
            ->leftJoin('slot', 'registration', 'reg', $qb->expr()->eq('reg.id', 'slot.registration_id'))
            ->leftJoin('slot', 'game', 'g', $qb->expr()->eq('g.id', 'slot.game_id'))
            ->leftJoin('sp', 'community_profile', 'cp', $qb->expr()->eq('cp.user_id', $user))
            ->leftJoin('s', 'run', 'run', $qb->expr()->eq('run.id', 's.event_id'))
            ->setParameter('defaultVisibility', PresenceVisibility::DEFAULT->value);

        if (null !== $viewerId) {
            $qb
                ->andWhere('NOT EXISTS (SELECT 1 FROM community_block b WHERE (b.blocker_id = :viewer AND b.blocked_id = '.$user.')'
                    .' OR (b.blocker_id = '.$user.' AND b.blocked_id = :viewer))')
                ->setParameter('viewer', $viewerId);
        }

        return $qb;
    }

    /**
     * @param array{owned: bool, activeAt: int, goalReached: bool} $a
     * @param array{owned: bool, activeAt: int, goalReached: bool} $b
     */
    private static function shownBefore(array $a, array $b): bool
    {
        if ($a['goalReached'] !== $b['goalReached']) {
            return !$a['goalReached'];
        }
        if ($a['owned'] !== $b['owned']) {
            return $a['owned'];
        }

        return $a['activeAt'] > $b['activeAt'];
    }

    /**
     * The slot shown for each member: a slot still played before one whose goal is reached (story 43.7), then own
     * slots before co-played ones, then the most recent activity (story 30.45). With $latestFirst (story 43.19), the
     * most recent activity alone.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array{userId: string, sessionId: string, game: string|null, owned: bool, activeAt: int, visibility: string, friend: bool, slotName: string|null, goalReached: bool, tracked: bool}>
     */
    private function bestSlotPerUser(array $rows, bool $latestFirst = false): array
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
            $visibility = $row['visibility'] ?? null;
            $candidate = [
                'userId' => $userId,
                'sessionId' => $sessionId,
                'game' => is_string($game) ? $game : null,
                'owned' => in_array($row['owned'] ?? null, [1, '1', true], true),
                'activeAt' => new \DateTimeImmutable($activeAtRaw)->getTimestamp(),
                'visibility' => is_string($visibility) ? $visibility : PresenceVisibility::DEFAULT->value,
                'friend' => in_array($row['friend'] ?? null, [1, '1', true], true),
                'slotName' => is_string($row['slot_name'] ?? null) ? $row['slot_name'] : null,
                'goalReached' => in_array($row['goal_reached'] ?? null, [1, '1', true], true),
                'tracked' => in_array($row['tracked'] ?? null, [1, '1', true], true),
            ];
            $current = $best[$userId] ?? null;
            if (null === $current || ($latestFirst ? $candidate['activeAt'] > $current['activeAt'] : self::shownBefore($candidate, $current))) {
                $best[$userId] = $candidate;
            }
        }

        return $best;
    }
}
