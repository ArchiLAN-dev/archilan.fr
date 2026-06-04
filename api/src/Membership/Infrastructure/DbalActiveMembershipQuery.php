<?php

declare(strict_types=1);

namespace App\Membership\Infrastructure;

use App\Membership\Application\ActiveMembershipQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalActiveMembershipQuery implements ActiveMembershipQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function hasActiveMembership(string $userId): bool
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

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
}
