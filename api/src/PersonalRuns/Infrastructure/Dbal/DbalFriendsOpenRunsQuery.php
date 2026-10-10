<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure\Dbal;

use App\PersonalRuns\Application\Query\FriendsOpenRunsQueryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use Doctrine\DBAL\Connection;

final readonly class DbalFriendsOpenRunsQuery implements FriendsOpenRunsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function openRunsFor(string $viewerId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('r.id', 'r.title', 'r.owner_id', 'r.seats_wanted', 'r.created_at')
            ->addSelect('(SELECT COUNT(*) FROM run_participant c WHERE c.personal_run_id = r.id AND c.user_id <> r.owner_id) AS joined')
            ->from('run', 'r')
            ->join('r', 'community_friendship', 'f', '(f.requester_id = r.owner_id AND f.addressee_id = :viewer) OR (f.addressee_id = r.owner_id AND f.requester_id = :viewer)')
            ->where('r.openness = :friends')
            ->andWhere('r.status = :draft')
            ->andWhere('f.status = :accepted')
            ->andWhere('r.owner_id <> :viewer')
            ->andWhere('NOT EXISTS (SELECT 1 FROM run_participant p WHERE p.personal_run_id = r.id AND p.user_id = :viewer)')
            ->andWhere('NOT EXISTS (SELECT 1 FROM community_block b WHERE (b.blocker_id = r.owner_id AND b.blocked_id = :viewer) OR (b.blocker_id = :viewer AND b.blocked_id = r.owner_id))')
            ->orderBy('r.created_at', 'DESC')
            ->setParameter('viewer', $viewerId)
            ->setParameter('friends', Run::OPEN_FRIENDS)
            ->setParameter('draft', Run::STATUS_DRAFT)
            ->setParameter('accepted', 'accepted')
            ->executeQuery()
            ->fetchAllAssociative();

        $runs = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $title = $row['title'] ?? null;
            $ownerId = $row['owner_id'] ?? null;
            $createdAt = $row['created_at'] ?? null;
            if (!is_string($id) || !is_string($title) || !is_string($ownerId) || !is_string($createdAt)) {
                continue;
            }
            $seats = $row['seats_wanted'] ?? null;
            $joined = $row['joined'] ?? 0;
            $runs[] = [
                'runId' => $id,
                'title' => $title,
                'ownerId' => $ownerId,
                'seatsWanted' => is_numeric($seats) ? (int) $seats : null,
                'joined' => is_numeric($joined) ? (int) $joined : 0,
                'createdAt' => new \DateTimeImmutable($createdAt),
            ];
        }

        return $runs;
    }
}
