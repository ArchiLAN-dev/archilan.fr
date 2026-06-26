<?php

declare(strict_types=1);

namespace App\Streaming\Infrastructure;

use App\Streaming\Application\ParticipantTwitchLinksQueryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * DBAL read joining a session's participants to their Identity user and Community profile.
 *
 * Crossing contexts on the read side mirrors {@see \App\Community\Infrastructure\DbalCommunityProfileQuery},
 * which already reads the Identity `"user"` table. Returns null when the parent session is absent so the
 * facade can answer 404. Banned/suspended/deleted users are filtered out; duplicate users (e.g. several
 * weekly attempts) are de-duplicated.
 */
final readonly class DbalParticipantTwitchLinksQuery implements ParticipantTwitchLinksQueryInterface
{
    private string $userTable;

    public function __construct(
        private Connection $connection,
    ) {
        // "user" is a reserved word in Postgres - quote it, like DbalCommunityProfileQuery does.
        $this->userTable = $connection->quoteSingleIdentifier('user');
    }

    public function forEvent(string $eventId): ?array
    {
        if (!$this->sessionExists('event', $eventId)) {
            return null;
        }

        // A completed event never surfaces streams: a participant who is live afterwards is streaming
        // something unrelated, so it must not appear under the event.
        if ($this->columnEquals('event', $eventId, 'status', 'completed')) {
            return [];
        }

        $qb = $this->connection->createQueryBuilder();
        $rows = $this->select($qb)
            ->from('registration', 'r')
            ->join('r', $this->userTable, 'u', $qb->expr()->eq('u.id', 'r.user_id'))
            ->join('u', 'community_profile', 'cp', $qb->expr()->eq('cp.user_id', 'u.id'))
            ->where($qb->expr()->eq('r.event_id', ':id'))
            // Only confirmed seats - cancelled registrations are excluded (story 7.7 AC5).
            ->andWhere($qb->expr()->eq('r.status', ':reserved'))
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->setParameter('id', $eventId)
            ->setParameter('reserved', 'reserved')
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->mapRows($rows);
    }

    public function forPersonalRun(string $runId): ?array
    {
        if (!$this->sessionExists('run', $runId)) {
            return null;
        }

        $qb = $this->connection->createQueryBuilder();
        $rows = $this->select($qb)
            ->from('run_participant', 'rp')
            ->join('rp', $this->userTable, 'u', $qb->expr()->eq('u.id', 'rp.user_id'))
            ->join('u', 'community_profile', 'cp', $qb->expr()->eq('cp.user_id', 'u.id'))
            ->where($qb->expr()->eq('rp.personal_run_id', ':id'))
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->setParameter('id', $runId)
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->mapRows($rows);
    }

    public function forWeeklyRun(string $weeklyRunId): ?array
    {
        if (!$this->sessionExists('weekly_runs', $weeklyRunId)) {
            return null;
        }

        // Streams are only relevant while the weekly run is open: a finished run yields no streams, so a
        // participant who happens to be live (on something unrelated) is not surfaced under this run.
        if (!$this->columnEquals('weekly_runs', $weeklyRunId, 'status', 'active')) {
            return [];
        }

        $qb = $this->connection->createQueryBuilder();
        $rows = $this->select($qb)
            ->from('weekly_entries', 'we')
            ->join('we', $this->userTable, 'u', $qb->expr()->eq('u.id', 'we.user_id'))
            ->join('u', 'community_profile', 'cp', $qb->expr()->eq('cp.user_id', 'u.id'))
            ->where($qb->expr()->eq('we.weekly_run_id', ':id'))
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->setParameter('id', $weeklyRunId)
            ->executeQuery()
            ->fetchAllAssociative();

        // A member may have several weekly entries (attempts) for the same run; mapRows de-duplicates by user.
        return $this->mapRows($rows);
    }

    private function select(QueryBuilder $qb): QueryBuilder
    {
        return $qb->select(
            'u.id AS user_id',
            'u.slug AS slug',
            'u.banned_at AS banned_at',
            'u.suspended_until AS suspended_until',
            // Profile display-name override, else the account name.
            'COALESCE(cp.display_name, u.display_name) AS display_name',
            'cp.social_links AS social_links',
        );
    }

    private function columnEquals(string $table, string $id, string $column, string $value): bool
    {
        $qb = $this->connection->createQueryBuilder();
        $found = $qb
            ->select('t.'.$column)
            ->from($table, 't')
            ->where($qb->expr()->eq('t.id', ':id'))
            ->setParameter('id', $id)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $value === $found;
    }

    private function sessionExists(string $table, string $id): bool
    {
        $qb = $this->connection->createQueryBuilder();
        $found = $qb
            ->select('1')
            ->from($table)
            ->where($qb->expr()->eq('id', ':id'))
            ->setParameter('id', $id)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return false !== $found;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{userId: string, slug: string, displayName: string|null, socialLinks: list<array{label: string, url: string}>}>
     */
    private function mapRows(array $rows): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $userId = $row['user_id'] ?? null;
            $slug = $row['slug'] ?? null;
            if (!is_string($userId) || !is_string($slug) || isset($seen[$userId])) {
                continue;
            }

            // Mirror of User::isAccessBlocked at the read layer (story 30.29).
            if ($this->isBlocked($row['banned_at'] ?? null, $row['suspended_until'] ?? null)) {
                continue;
            }

            $seen[$userId] = true;
            $rawDisplayName = $row['display_name'] ?? null;
            $displayName = is_string($rawDisplayName) && '' !== trim($rawDisplayName) ? $rawDisplayName : null;
            $out[] = [
                'userId' => $userId,
                'slug' => $slug,
                'displayName' => $displayName,
                'socialLinks' => $this->decodeSocialLinks($row['social_links'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function decodeSocialLinks(mixed $raw): array
    {
        if (!is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $links = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && is_string($entry['label'] ?? null) && is_string($entry['url'] ?? null)) {
                $links[] = ['label' => $entry['label'], 'url' => $entry['url']];
            }
        }

        return $links;
    }

    private function isBlocked(mixed $bannedAt, mixed $suspendedUntil): bool
    {
        if (is_string($bannedAt) && '' !== $bannedAt) {
            return true;
        }

        if (!is_string($suspendedUntil) || '' === $suspendedUntil) {
            return false;
        }

        try {
            return new \DateTimeImmutable($suspendedUntil) > new \DateTimeImmutable();
        } catch (\Exception) {
            // Unparseable timestamp: don't block on a value we can't read.
            return false;
        }
    }
}
