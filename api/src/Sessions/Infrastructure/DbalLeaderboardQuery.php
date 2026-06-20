<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

use App\Identity\Domain\User;
use App\PersonalRuns\Domain\Run;
use App\Registrations\Domain\Registration;
use App\Sessions\Application\LeaderboardQueryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DbalLeaderboardQuery implements LeaderboardQueryInterface
{
    private string $sessionTable;
    private string $slotTable;
    private string $registrationTable;
    private string $runTable;
    private string $userTable;

    public function __construct(private Connection $connection, EntityManagerInterface $em)
    {
        $this->sessionTable = $em->getClassMetadata(Session::class)->getTableName();
        $this->slotTable = $em->getClassMetadata(SessionSlot::class)->getTableName();
        $this->registrationTable = $em->getClassMetadata(Registration::class)->getTableName();
        $this->runTable = $em->getClassMetadata(Run::class)->getTableName();
        $this->userTable = $connection->quoteSingleIdentifier($em->getClassMetadata(User::class)->getTableName());
    }

    public function computeAggregatePage(string $axis, ?string $eventId, int $limit, int $offset): array
    {
        $selectValue = 'goals' === $axis ? 'COUNT(slot.id)' : 'COALESCE(SUM(slot.checks_done), 0)';
        $axisFilter = 'goals' === $axis
            ? 'slot.goal_reached_at IS NOT NULL'
            : 'NOT (slot.was_released AND slot.goal_reached_at IS NULL)';

        $eventQb = $this->connection->createQueryBuilder();
        $eventQb->select('reg.user_id AS user_id', $selectValue.' AS value')
            ->from($this->slotTable, 'slot')
            ->join('slot', $this->registrationTable, 'reg', $eventQb->expr()->eq('reg.id', 'slot.registration_id'))
            ->join('slot', $this->sessionTable, 's', $eventQb->expr()->eq('s.id', 'slot.session_id'))
            ->where($eventQb->expr()->eq('s.status', ':status'))
            ->andWhere($axisFilter)
            ->setParameter('status', 'finished')
            ->groupBy('reg.user_id');

        if (null !== $eventId) {
            $eventQb->andWhere($eventQb->expr()->eq('s.event_id', ':eventId'))
                ->setParameter('eventId', $eventId);
        }

        $eventRows = $eventQb->executeQuery()->fetchAllAssociative();

        $prRows = [];
        if (null === $eventId) {
            $prQb = $this->connection->createQueryBuilder();
            $prQb->select('slot.registration_id AS user_id', $selectValue.' AS value')
                ->from($this->slotTable, 'slot')
                ->join('slot', $this->sessionTable, 's', $prQb->expr()->eq('s.id', 'slot.session_id'))
                ->join('s', $this->runTable, 'pr', $prQb->expr()->eq('pr.session_id', 's.id'))
                ->where($prQb->expr()->eq('s.status', ':status'))
                ->andWhere($axisFilter)
                ->setParameter('status', 'finished')
                ->groupBy('slot.registration_id');
            $prRows = $prQb->executeQuery()->fetchAllAssociative();
        }

        /** @var array<string, int> $totals */
        $totals = [];
        foreach (array_merge($eventRows, $prRows) as $row) {
            $userId = is_string($row['user_id'] ?? null) ? $row['user_id'] : '';
            if ('' === $userId) {
                continue;
            }
            $value = is_numeric($row['value'] ?? null) ? (int) $row['value'] : 0;
            $totals[$userId] = ($totals[$userId] ?? 0) + $value;
        }

        $userIds = array_keys($totals);
        $total = count($userIds);

        if ([] === $userIds) {
            return [[], 0];
        }

        $userQb = $this->connection->createQueryBuilder();
        $placeholders = array_map(
            static fn (string $uid): string => $userQb->createNamedParameter($uid),
            $userIds,
        );
        $userRows = $userQb
            // Display the community pseudo (override) falling back to the account name.
            ->select('u.id AS id', "COALESCE(u.slug, '') AS slug", 'COALESCE(cp.display_name, u.display_name) AS display_name')
            ->from($this->userTable, 'u')
            ->leftJoin('u', 'community_profile', 'cp', 'cp.user_id = u.id')
            ->where($userQb->expr()->in('u.id', $placeholders))
            ->executeQuery()
            ->fetchAllAssociative();

        $userMap = [];
        foreach ($userRows as $userRow) {
            $id = is_string($userRow['id'] ?? null) ? $userRow['id'] : '';
            if ('' !== $id) {
                $userMap[$id] = $userRow;
            }
        }

        /** @var list<array{slug: string, displayName: string, sortName: string, value: int}> $entries */
        $entries = [];
        foreach ($totals as $userId => $value) {
            $userRow = $userMap[$userId] ?? null;
            if (null === $userRow) {
                continue;
            }
            $slug = is_string($userRow['slug'] ?? null) ? $userRow['slug'] : '';
            $displayName = is_string($userRow['display_name'] ?? null) ? $userRow['display_name'] : '';
            $sortName = mb_strtolower('' !== $displayName ? $displayName : $slug);
            $entries[] = ['slug' => $slug, 'displayName' => $displayName, 'sortName' => $sortName, 'value' => $value];
        }

        usort($entries, static function (array $a, array $b): int {
            if ($a['value'] !== $b['value']) {
                return $b['value'] <=> $a['value'];
            }

            return strcmp($a['sortName'], $b['sortName']);
        });

        $pageEntries = array_slice($entries, $offset, $limit);

        $finalEntries = [];
        foreach ($pageEntries as $entry) {
            $finalEntries[] = ['slug' => $entry['slug'], 'displayName' => $entry['displayName'], 'value' => $entry['value']];
        }

        return [$finalEntries, $total];
    }

    public function computeSpeedPage(?string $eventId, int $limit, int $offset): array
    {
        $eventQb = $this->connection->createQueryBuilder();
        $eventQb->select(
            'reg.user_id AS user_id',
            'MIN(slot.goal_reached_at) AS earliest_goal_at',
            's.started_at',
        )
            ->from($this->slotTable, 'slot')
            ->join('slot', $this->registrationTable, 'reg', $eventQb->expr()->eq('reg.id', 'slot.registration_id'))
            ->join('slot', $this->sessionTable, 's', $eventQb->expr()->eq('s.id', 'slot.session_id'))
            ->where($eventQb->expr()->eq('s.status', ':status'))
            ->andWhere($eventQb->expr()->isNotNull('slot.goal_reached_at'))
            ->setParameter('status', 'finished')
            ->groupBy('reg.user_id', 's.id', 's.started_at');

        if (null !== $eventId) {
            $eventQb->andWhere($eventQb->expr()->eq('s.event_id', ':eventId'))
                ->setParameter('eventId', $eventId);
        }

        $allRows = $eventQb->executeQuery()->fetchAllAssociative();

        if (null === $eventId) {
            $prQb = $this->connection->createQueryBuilder();
            $prQb->select(
                'slot.registration_id AS user_id',
                'MIN(slot.goal_reached_at) AS earliest_goal_at',
                's.started_at',
            )
                ->from($this->slotTable, 'slot')
                ->join('slot', $this->sessionTable, 's', $prQb->expr()->eq('s.id', 'slot.session_id'))
                ->join('s', $this->runTable, 'pr', $prQb->expr()->eq('pr.session_id', 's.id'))
                ->where($prQb->expr()->eq('s.status', ':status'))
                ->andWhere($prQb->expr()->isNotNull('slot.goal_reached_at'))
                ->setParameter('status', 'finished')
                ->groupBy('slot.registration_id', 's.id', 's.started_at');
            $allRows = array_merge($allRows, $prQb->executeQuery()->fetchAllAssociative());
        }

        /** @var array<string, int> $scores */
        $scores = [];
        foreach ($allRows as $row) {
            $userId = is_string($row['user_id'] ?? null) ? $row['user_id'] : '';
            if ('' === $userId) {
                continue;
            }
            $goalAt = is_string($row['earliest_goal_at'] ?? null) ? $row['earliest_goal_at'] : null;
            $startedAt = is_string($row['started_at'] ?? null) ? $row['started_at'] : null;
            if (null === $goalAt || null === $startedAt) {
                continue;
            }
            try {
                $seconds = (new \DateTimeImmutable($goalAt))->getTimestamp()
                    - (new \DateTimeImmutable($startedAt))->getTimestamp();
            } catch (\Exception) {
                continue;
            }
            if ($seconds <= 0) {
                continue;
            }
            if (!isset($scores[$userId]) || $seconds < $scores[$userId]) {
                $scores[$userId] = $seconds;
            }
        }

        $userIds = array_keys($scores);
        if ([] === $userIds) {
            return [[], 0];
        }

        $userQb = $this->connection->createQueryBuilder();
        $placeholders = array_map(
            static fn (string $uid): string => $userQb->createNamedParameter($uid),
            $userIds,
        );
        $userRows = $userQb
            // Display the community pseudo (override) falling back to the account name.
            ->select('u.id AS id', "COALESCE(u.slug, '') AS slug", 'COALESCE(cp.display_name, u.display_name) AS display_name')
            ->from($this->userTable, 'u')
            ->leftJoin('u', 'community_profile', 'cp', 'cp.user_id = u.id')
            ->where($userQb->expr()->in('u.id', $placeholders))
            ->executeQuery()
            ->fetchAllAssociative();

        $userMap = [];
        foreach ($userRows as $userRow) {
            $id = is_string($userRow['id'] ?? null) ? $userRow['id'] : '';
            if ('' !== $id) {
                $userMap[$id] = $userRow;
            }
        }

        /** @var list<array{slug: string, displayName: string, sortName: string, value: int}> $entries */
        $entries = [];
        foreach ($scores as $userId => $value) {
            $userRow = $userMap[$userId] ?? null;
            if (null === $userRow) {
                continue;
            }
            $slug = is_string($userRow['slug'] ?? null) ? $userRow['slug'] : '';
            $displayName = is_string($userRow['display_name'] ?? null) ? $userRow['display_name'] : '';
            $sortName = mb_strtolower('' !== $displayName ? $displayName : $slug);
            $entries[] = ['slug' => $slug, 'displayName' => $displayName, 'sortName' => $sortName, 'value' => $value];
        }

        usort($entries, static function (array $a, array $b): int {
            if ($a['value'] !== $b['value']) {
                return $a['value'] <=> $b['value'];
            }

            return strcmp($a['sortName'], $b['sortName']);
        });

        $total = count($entries);
        $pageEntries = array_slice($entries, $offset, $limit);

        $finalEntries = [];
        foreach ($pageEntries as $entry) {
            $finalEntries[] = ['slug' => $entry['slug'], 'displayName' => $entry['displayName'], 'value' => $entry['value']];
        }

        return [$finalEntries, $total];
    }
}
