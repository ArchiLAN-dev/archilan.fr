<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

use App\Sessions\Application\PlayerConnectionQueryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DbalPlayerConnectionQuery implements PlayerConnectionQueryInterface
{
    private string $sessionSlotTable;
    private string $sessionTable;

    public function __construct(private Connection $connection, EntityManagerInterface $em)
    {
        $this->sessionSlotTable = $em->getClassMetadata(SessionSlot::class)->getTableName();
        $this->sessionTable = $em->getClassMetadata(Session::class)->getTableName();
    }

    public function findLatestSessionIdByRegistrationId(string $registrationId): ?string
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb->select('DISTINCT ss.session_id')
            ->from($this->sessionSlotTable, 'ss')
            ->where($qb->expr()->eq('ss.registration_id', ':registrationId'))
            ->setParameter('registrationId', $registrationId)
            ->executeQuery()
            ->fetchFirstColumn();

        /** @var list<string> $sessionIds */
        $sessionIds = array_values(array_filter($rows, 'is_string'));

        if ([] === $sessionIds) {
            return null;
        }

        $qb2 = $this->connection->createQueryBuilder();
        $placeholders = array_map(fn (string $id): string => $qb2->createNamedParameter($id), $sessionIds);
        $result = $qb2->select('s.id')
            ->from($this->sessionTable, 's')
            ->where($qb2->expr()->in('s.id', $placeholders))
            ->orderBy('s.created_at', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if (false === $result || !is_string($result)) {
            return null;
        }

        return $result;
    }
}
