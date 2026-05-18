<?php

declare(strict_types=1);

namespace App\Membership\Application;

use App\Membership\Domain\Membership;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ActiveMembershipQuery implements ActiveMembershipQueryInterface
{
    private string $table;

    public function __construct(
        EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
        $this->table = $entityManager->getClassMetadata(Membership::class)->getTableName();
    }

    public function hasActiveMembership(string $userId): bool
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $qb = $this->connection->createQueryBuilder();
        $result = $qb
            ->select('1')
            ->from($this->table, 'm')
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
