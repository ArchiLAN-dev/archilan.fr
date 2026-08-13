<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Dbal;

use App\Identity\Application\Query\AdminUserActivityEntry;
use App\Identity\Application\Query\AdminUserActivityQueryInterface;
use Doctrine\DBAL\Connection;

/**
 * Reads the five audit trails that had no read path at all before story 36.5, and merges them into one
 * timeline.
 *
 * Five bounded queries merged in PHP rather than one SQL union: the sources have unrelated shapes, each
 * carries its own join (a run title, an event title), and each is already capped at $limit - so the
 * merge is at most 5 x limit rows. The same shape as DbalCommunityPresenceQuery, which likewise merges
 * two dissimilar reads.
 *
 * Three of these tables belong to other contexts (`run_audit_log` to Sessions, `event_private_access_log`
 * to Events). Reading another context's table from a read model is the established pattern here - see
 * DbalCommunityPresenceQuery, which reads `session`, `registration`, `run` and `game` from Community.
 */
final readonly class DbalAdminUserActivityQuery implements AdminUserActivityQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function forUser(string $userId, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $rows = [
            ...$this->roleChanges($userId, $limit),
            ...$this->adminCreations($userId, $limit),
            ...$this->deletions($userId, $limit),
            ...$this->runActions($userId, $limit),
            ...$this->privateEventAccess($userId, $limit),
            ...$this->adminActions($userId, $limit),
        ];

        // Newest first; the id tiebreak keeps the order stable between two calls when timestamps match
        // (the backfills wrote whole batches on the same second).
        usort($rows, static fn (array $a, array $b): int => $b['occurredAt'] <=> $a['occurredAt']
            ?: strcmp($b['sortId'], $a['sortId']));

        $out = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            unset($row['sortId']);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Both faces of a role change: what this user went through, and what they did to others.
     *
     * @return list<array{type: string, occurredAt: string, counterpartId: string|null, previousRole: string|null, newRole: string|null, subject: string|null, subjectId: string|null, granted: bool|null, sortId: string}>
     */
    private function roleChanges(string $userId, int $limit): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('a.id', 'a.target_user_id', 'a.admin_user_id', 'a.previous_role', 'a.new_role', 'a.changed_at')
            ->from('role_change_audit', 'a')
            ->where($qb->expr()->or(
                $qb->expr()->eq('a.target_user_id', ':id'),
                $qb->expr()->eq('a.admin_user_id', ':id'),
            ))
            ->setParameter('id', $userId)
            ->orderBy('a.changed_at', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $occurredAt = $this->atom($row['changed_at'] ?? null);
            $id = $this->string($row['id'] ?? null);
            if (null === $occurredAt || null === $id) {
                continue;
            }
            $isTarget = $this->string($row['target_user_id'] ?? null) === $userId;
            $out[] = [
                'type' => $isTarget
                    ? AdminUserActivityEntry::TYPE_ROLE_CHANGED
                    : AdminUserActivityEntry::TYPE_ROLE_CHANGE_PERFORMED,
                'occurredAt' => $occurredAt,
                'counterpartId' => $isTarget
                    ? $this->string($row['admin_user_id'] ?? null)
                    : $this->string($row['target_user_id'] ?? null),
                'previousRole' => $this->string($row['previous_role'] ?? null),
                'newRole' => $this->string($row['new_role'] ?? null),
                'subject' => null,
                'subjectId' => null,
                'granted' => null,
                'sortId' => $id,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{type: string, occurredAt: string, counterpartId: string|null, previousRole: string|null, newRole: string|null, subject: string|null, subjectId: string|null, granted: bool|null, sortId: string}>
     */
    private function adminCreations(string $userId, int $limit): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('a.id', 'a.created_user_id', 'a.creator_user_id', 'a.created_at')
            ->from('admin_creation_audit', 'a')
            ->where($qb->expr()->or(
                $qb->expr()->eq('a.created_user_id', ':id'),
                $qb->expr()->eq('a.creator_user_id', ':id'),
            ))
            ->setParameter('id', $userId)
            ->orderBy('a.created_at', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $occurredAt = $this->atom($row['created_at'] ?? null);
            $id = $this->string($row['id'] ?? null);
            if (null === $occurredAt || null === $id) {
                continue;
            }
            $isCreated = $this->string($row['created_user_id'] ?? null) === $userId;
            $out[] = [
                'type' => $isCreated
                    ? AdminUserActivityEntry::TYPE_ADMIN_ACCOUNT_CREATED
                    : AdminUserActivityEntry::TYPE_ADMIN_ACCOUNT_CREATED_BY,
                'occurredAt' => $occurredAt,
                'counterpartId' => $isCreated
                    ? $this->string($row['creator_user_id'] ?? null)
                    : $this->string($row['created_user_id'] ?? null),
                'previousRole' => null,
                'newRole' => null,
                'subject' => null,
                'subjectId' => null,
                'granted' => null,
                'sortId' => $id,
            ];
        }

        return $out;
    }

    /**
     * The stored email hash is deliberately not read: it exists to cross-check a deletion, and showing
     * it on an admin screen adds nothing.
     *
     * @return list<array{type: string, occurredAt: string, counterpartId: string|null, previousRole: string|null, newRole: string|null, subject: string|null, subjectId: string|null, granted: bool|null, sortId: string}>
     */
    private function deletions(string $userId, int $limit): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('a.id', 'a.reason', 'a.deleted_at')
            ->from('deletion_audit', 'a')
            ->where($qb->expr()->eq('a.user_id', ':id'))
            ->setParameter('id', $userId)
            ->orderBy('a.deleted_at', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $occurredAt = $this->atom($row['deleted_at'] ?? null);
            $id = $this->string($row['id'] ?? null);
            if (null === $occurredAt || null === $id) {
                continue;
            }
            $out[] = [
                'type' => AdminUserActivityEntry::TYPE_ACCOUNT_DELETED,
                'occurredAt' => $occurredAt,
                'counterpartId' => null,
                'previousRole' => null,
                'newRole' => null,
                'subject' => $this->string($row['reason'] ?? null),
                'subjectId' => null,
                'granted' => null,
                'sortId' => $id,
            ];
        }

        return $out;
    }

    /**
     * Admin actions this user performed on Archipelago runs. `run_audit_log` records the acting admin,
     * never the run's owner, so this is "what they did", not "what happened to their runs".
     *
     * Naming trap: `run_audit_log.run_id` is a **session** id, not a PersonalRuns `run` id - in the
     * Sessions context a "run" is a running multiworld. Joining `run` matched zero of the 55 rows in
     * the dev database. The label therefore comes from what the session belongs to, and `session.event_id`
     * is itself overloaded (a real Event, else a personal Run id, as SessionRecapAudience documents),
     * so both are tried.
     *
     * @return list<array{type: string, occurredAt: string, counterpartId: string|null, previousRole: string|null, newRole: string|null, subject: string|null, subjectId: string|null, granted: bool|null, sortId: string}>
     */
    private function runActions(string $userId, int $limit): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('a.id', 'a.action', 'a.run_id', 'COALESCE(e.title, r.title) AS context_title', 'a.created_at')
            ->from('run_audit_log', 'a')
            ->leftJoin('a', 'session', 's', $qb->expr()->eq('s.id', 'a.run_id'))
            ->leftJoin('s', 'event', 'e', $qb->expr()->eq('e.id', 's.event_id'))
            ->leftJoin('s', 'run', 'r', $qb->expr()->eq('r.id', 's.event_id'))
            ->where($qb->expr()->eq('a.admin_user_id', ':id'))
            ->setParameter('id', $userId)
            ->orderBy('a.created_at', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $occurredAt = $this->atom($row['created_at'] ?? null);
            $id = $this->string($row['id'] ?? null);
            if (null === $occurredAt || null === $id) {
                continue;
            }
            $out[] = [
                'type' => AdminUserActivityEntry::TYPE_RUN_ADMIN_ACTION,
                'occurredAt' => $occurredAt,
                'counterpartId' => null,
                'previousRole' => null,
                'newRole' => $this->string($row['action'] ?? null),
                'subject' => $this->string($row['context_title'] ?? null),
                // Deliberately not linked: a running session has no public page, and a finished one only
                // has a recap when it was built. A dead link is worse than a plain label.
                'subjectId' => null,
                'granted' => null,
                'sortId' => $id,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{type: string, occurredAt: string, counterpartId: string|null, previousRole: string|null, newRole: string|null, subject: string|null, subjectId: string|null, granted: bool|null, sortId: string}>
     */
    private function privateEventAccess(string $userId, int $limit): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('a.id', 'a.event_id', 'e.title AS event_title', 'a.granted', 'a.created_at')
            ->from('event_private_access_log', 'a')
            ->leftJoin('a', 'event', 'e', $qb->expr()->eq('e.id', 'a.event_id'))
            ->where($qb->expr()->eq('a.user_id', ':id'))
            ->setParameter('id', $userId)
            ->orderBy('a.created_at', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $occurredAt = $this->atom($row['created_at'] ?? null);
            $id = $this->string($row['id'] ?? null);
            if (null === $occurredAt || null === $id) {
                continue;
            }
            $out[] = [
                'type' => AdminUserActivityEntry::TYPE_PRIVATE_EVENT_ACCESS,
                'occurredAt' => $occurredAt,
                'counterpartId' => null,
                'previousRole' => null,
                'newRole' => null,
                'subject' => $this->string($row['event_title'] ?? null),
                'subjectId' => $this->string($row['event_id'] ?? null),
                'granted' => (bool) ($row['granted'] ?? false),
                'sortId' => $id,
            ];
        }

        return $out;
    }

    /**
     * Both faces of an admin action on an account (story 36.6): what was done to this member, and what
     * they did to others. Added as a sixth source so the trail written by story 36.6 is actually read -
     * writing one the sheet never shows would repeat exactly what 36.5 set out to fix.
     *
     * @return list<array{type: string, occurredAt: string, counterpartId: string|null, previousRole: string|null, newRole: string|null, subject: string|null, subjectId: string|null, granted: bool|null, sortId: string}>
     */
    private function adminActions(string $userId, int $limit): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('a.id', 'a.target_user_id', 'a.admin_user_id', 'a.action', 'a.created_at')
            ->from('admin_user_action_audit', 'a')
            ->where($qb->expr()->or(
                $qb->expr()->eq('a.target_user_id', ':id'),
                $qb->expr()->eq('a.admin_user_id', ':id'),
            ))
            ->setParameter('id', $userId)
            ->orderBy('a.created_at', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $occurredAt = $this->atom($row['created_at'] ?? null);
            $id = $this->string($row['id'] ?? null);
            if (null === $occurredAt || null === $id) {
                continue;
            }
            $isTarget = $this->string($row['target_user_id'] ?? null) === $userId;
            $out[] = [
                'type' => $isTarget
                    ? AdminUserActivityEntry::TYPE_ADMIN_ACTION_RECEIVED
                    : AdminUserActivityEntry::TYPE_ADMIN_ACTION_PERFORMED,
                'occurredAt' => $occurredAt,
                'counterpartId' => $isTarget
                    ? $this->string($row['admin_user_id'] ?? null)
                    : $this->string($row['target_user_id'] ?? null),
                'previousRole' => null,
                // The action name rides in newRole, as it does for run_admin_action - the field is the
                // row's "what happened" slot rather than a role-specific one.
                'newRole' => $this->string($row['action'] ?? null),
                'subject' => null,
                'subjectId' => null,
                'granted' => null,
                'sortId' => $id,
            ];
        }

        return $out;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Normalised where the database's timestamp format is known, so every caller compares and renders
     * the same string shape.
     */
    private function atom(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value)->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
