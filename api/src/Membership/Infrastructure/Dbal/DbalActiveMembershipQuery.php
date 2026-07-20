<?php

declare(strict_types=1);

namespace App\Membership\Infrastructure\Dbal;

use App\Membership\Application\Query\ActiveMembershipQueryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalActiveMembershipQuery implements ActiveMembershipQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function hasActiveMembership(string $userId): bool
    {
        $now = new \DateTimeImmutable()->format(\DateTimeInterface::ATOM);

        $qb = $this->connection->createQueryBuilder();
        $result = $qb
            ->select('1')
            ->from('memberships', 'm')
            ->where($qb->expr()->eq('m.user_id', ':userId'))
            ->andWhere($qb->expr()->eq('m.status', ':status'))
            ->andWhere($qb->expr()->gte('m.expires_at', ':now'))
            ->setParameter('userId', $userId)
            ->setParameter('status', 'active')
            ->setParameter('now', $now)
            ->executeQuery()
            ->fetchOne();

        return false !== $result;
    }

    public function activeMemberIds(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        $now = new \DateTimeImmutable()->format(\DateTimeInterface::ATOM);

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('DISTINCT m.user_id')
            ->from('memberships', 'm')
            ->where($qb->expr()->in('m.user_id', ':ids'))
            ->andWhere($qb->expr()->eq('m.status', ':status'))
            ->andWhere($qb->expr()->gte('m.expires_at', ':now'))
            ->setParameter('ids', $userIds, ArrayParameterType::STRING)
            ->setParameter('status', 'active')
            ->setParameter('now', $now)
            ->executeQuery()
            ->fetchFirstColumn();

        $memberIds = [];
        foreach ($rows as $row) {
            if (is_string($row)) {
                $memberIds[] = $row;
            }
        }

        return $memberIds;
    }
}
