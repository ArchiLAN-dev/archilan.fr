<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Dbal;

use App\Sessions\Application\Query\PlayerConnectionQueryInterface;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotCoPlayer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DbalPlayerConnectionQuery implements PlayerConnectionQueryInterface
{
    private string $sessionSlotTable;
    private string $sessionTable;
    private string $coPlayerTable;

    public function __construct(private Connection $connection, EntityManagerInterface $em)
    {
        $this->sessionSlotTable = $em->getClassMetadata(SessionSlot::class)->getTableName();
        $this->sessionTable = $em->getClassMetadata(Session::class)->getTableName();
        $this->coPlayerTable = $em->getClassMetadata(SlotCoPlayer::class)->getTableName();
    }

    public function findLatestSessionIdForPlayer(string $eventId, string $registrationId, string $userId): ?string
    {
        $coPlayed = $this->connection->createQueryBuilder();
        $coPlayed->select('cp.slot_id')
            ->from($this->coPlayerTable, 'cp')
            ->where('cp.user_id = :userId');

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb->select('DISTINCT ss.session_id')
            ->from($this->sessionSlotTable, 'ss')
            ->where($qb->expr()->or(
                $qb->expr()->eq('ss.registration_id', ':registrationId'),
                $qb->expr()->in('ss.slot_id', $coPlayed->getSQL()),
            ))
            ->setParameter('registrationId', $registrationId)
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchFirstColumn();

        /** @var list<string> $sessionIds */
        $sessionIds = array_values(array_filter($rows, is_string(...)));

        if ([] === $sessionIds) {
            return null;
        }

        $qb2 = $this->connection->createQueryBuilder();
        $placeholders = array_map(fn (string $id): string => $qb2->createNamedParameter($id), $sessionIds);
        $result = $qb2->select('s.id')
            ->from($this->sessionTable, 's')
            ->where($qb2->expr()->in('s.id', $placeholders))
            ->andWhere($qb2->expr()->eq('s.event_id', $qb2->createNamedParameter($eventId)))
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
