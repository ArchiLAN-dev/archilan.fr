<?php

declare(strict_types=1);

namespace App\Registrations\Infrastructure;

use App\Registrations\Application\RegistrationCounterQueryInterface;
use App\Registrations\Domain\Registration;
use Doctrine\DBAL\Connection;

final readonly class DbalRegistrationCounterQuery implements RegistrationCounterQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function countConfirmed(string $eventId): int
    {
        $qb = $this->connection->createQueryBuilder();

        $raw = $qb
            ->select('COUNT(r.id)')
            ->from('registration', 'r')
            ->where($qb->expr()->eq('r.event_id', ':eventId'))
            ->andWhere($qb->expr()->neq('r.status', ':cancelled'))
            ->setParameter('eventId', $eventId)
            ->setParameter('cancelled', Registration::STATUS_CANCELLED)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($raw) ? (int) $raw : 0;
    }
}
