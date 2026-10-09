<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure\Dbal;

use App\PersonalRuns\Application\Query\RunInviteFriendsQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalRunInviteFriendsQuery implements RunInviteFriendsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function canInvite(string $inviterId, string $inviteeId): bool
    {
        $qb = $this->connection->createQueryBuilder();
        $friends = $qb
            ->select('1')
            ->from('community_friendship', 'f')
            ->where('f.status = :accepted')
            ->andWhere('(f.requester_id = :a AND f.addressee_id = :b) OR (f.requester_id = :b AND f.addressee_id = :a)')
            ->setParameter('accepted', 'accepted')
            ->setParameter('a', $inviterId)
            ->setParameter('b', $inviteeId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        if (false === $friends) {
            return false;
        }

        $qb = $this->connection->createQueryBuilder();
        $blocked = $qb
            ->select('1')
            ->from('community_block', 'b')
            ->where('(b.blocker_id = :a AND b.blocked_id = :b) OR (b.blocker_id = :b AND b.blocked_id = :a)')
            ->setParameter('a', $inviterId)
            ->setParameter('b', $inviteeId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return false === $blocked;
    }
}
