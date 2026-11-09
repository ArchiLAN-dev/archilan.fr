<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Doctrine;

use App\Sessions\Domain\Entity\PasseurOpening;
use App\Sessions\Domain\Repository\PasseurOpeningRepositoryInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePasseurOpeningRepository implements PasseurOpeningRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * An `INSERT ... ON CONFLICT DO NOTHING`: a unique violation through the ORM would close the entity manager.
     */
    public function claim(PasseurOpening $opening): ?PasseurOpening
    {
        $written = $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO passeur_opening (id, session_id, request_key, buyer_user_id, buyer_slot, finding_slot, absent_user_id, location_id, location_name, item_name, kind, event_id, run_id, price, share, status, created_at)
             VALUES (:id, :session, :requestKey, :buyer, :buyerSlot, :findingSlot, :absent, :location, :locationName, :itemName, :kind, :event, :run, :price, 0, :status, :createdAt)
             ON CONFLICT DO NOTHING',
            [
                'id' => $opening->getId(),
                'session' => $opening->getSessionId(),
                'requestKey' => $opening->getRequestKey(),
                'buyer' => $opening->getBuyerUserId(),
                'buyerSlot' => $opening->getBuyerSlot(),
                'findingSlot' => $opening->getFindingSlot(),
                'absent' => $opening->getAbsentUserId(),
                'location' => $opening->getLocationId(),
                'locationName' => $opening->getLocationName(),
                'itemName' => $opening->getItemName(),
                'kind' => $opening->getKind(),
                'event' => $opening->getEventId(),
                'run' => $opening->getRunId(),
                'price' => $opening->getPrice(),
                'status' => $opening->getStatus(),
                'createdAt' => $opening->getCreatedAt(),
            ],
            ['createdAt' => Types::DATETIMETZ_IMMUTABLE, 'location' => Types::BIGINT],
        );

        return 1 === $written ? $this->entityManager->find(PasseurOpening::class, $opening->getId()) : null;
    }

    public function findByRequestKey(string $requestKey): ?PasseurOpening
    {
        return $this->entityManager->getRepository(PasseurOpening::class)->findOneBy(['requestKey' => $requestKey]);
    }

    public function countForWorld(string $sessionId, int $findingSlot, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $qb = $this->entityManager->getConnection()->createQueryBuilder();
        $count = $qb->select('COUNT(*)')
            ->from('passeur_opening')
            ->where('session_id = :session')
            ->andWhere('finding_slot = :slot')
            ->andWhere('status <> :failed')
            ->andWhere('created_at >= :from')
            ->andWhere('created_at < :to')
            ->setParameter('session', $sessionId)
            ->setParameter('slot', $findingSlot)
            ->setParameter('failed', PasseurOpening::STATUS_FAILED)
            ->setParameter('from', $from, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('to', $to, Types::DATETIMETZ_IMMUTABLE)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
