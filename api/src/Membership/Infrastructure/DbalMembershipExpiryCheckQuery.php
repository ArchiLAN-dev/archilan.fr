<?php

declare(strict_types=1);

namespace App\Membership\Infrastructure;

use App\Membership\Application\MembershipExpiryCheckQueryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class DbalMembershipExpiryCheckQuery implements MembershipExpiryCheckQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<string>
     */
    public function findExpiredActiveIds(\DateTimeImmutable $now): array
    {
        $qb = $this->connection->createQueryBuilder();
        $ids = $qb
            ->select('m.id')
            ->from('memberships', 'm')
            ->where($qb->expr()->eq('m.status', ':status'))
            ->andWhere($qb->expr()->lte('m.expires_at', ':now'))
            ->setParameter('status', 'active')
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_filter($ids, static fn (mixed $id): bool => is_string($id)));
    }

    /**
     * @return list<string>
     */
    public function findPendingReminderIds(\DateTimeImmutable $now, int $daysLeft): array
    {
        $deadline = $now->add(new \DateInterval('P'.$daysLeft.'D'));
        $reminderField = 30 === $daysLeft ? 'm.reminder_30_sent_at' : 'm.reminder_7_sent_at';

        $qb = $this->connection->createQueryBuilder();
        $ids = $qb
            ->select('m.id')
            ->from('memberships', 'm')
            ->where($qb->expr()->eq('m.status', ':status'))
            ->andWhere($qb->expr()->gt('m.expires_at', ':now'))
            ->andWhere($qb->expr()->lte('m.expires_at', ':deadline'))
            ->andWhere($qb->expr()->isNull($reminderField))
            ->setParameter('status', 'active')
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('deadline', $deadline, Types::DATETIMETZ_IMMUTABLE)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_filter($ids, static fn (mixed $id): bool => is_string($id)));
    }
}
