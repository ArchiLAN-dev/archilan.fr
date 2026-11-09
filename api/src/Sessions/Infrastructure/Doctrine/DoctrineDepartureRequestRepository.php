<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Doctrine;

use App\Sessions\Domain\Entity\DepartureRequest;
use App\Sessions\Domain\Repository\DepartureRequestRepositoryInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineDepartureRequestRepository implements DepartureRequestRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * An `INSERT ... ON CONFLICT DO NOTHING`: a second open request on the slot (a double click, the scheduled pass at
     * the same time) must not close the entity manager with a unique violation.
     */
    public function open(DepartureRequest $request): ?DepartureRequest
    {
        $written = $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO departure_request (id, session_id, slot_index, slot_name, origin, status, created_at)
             VALUES (:id, :session, :slotIndex, :slotName, :origin, :status, :createdAt)
             ON CONFLICT DO NOTHING',
            [
                'id' => $request->getId(),
                'session' => $request->getSessionId(),
                'slotIndex' => $request->getSlotIndex(),
                'slotName' => $request->getSlotName(),
                'origin' => $request->getOrigin(),
                'status' => $request->getStatus(),
                'createdAt' => $request->getCreatedAt(),
            ],
            ['createdAt' => Types::DATETIMETZ_IMMUTABLE],
        );

        return 1 === $written ? $this->entityManager->find(DepartureRequest::class, $request->getId()) : null;
    }

    public function findById(string $id): ?DepartureRequest
    {
        return $this->entityManager->find(DepartureRequest::class, $id);
    }

    public function findOpen(string $sessionId, string $slotName): ?DepartureRequest
    {
        return $this->entityManager->getRepository(DepartureRequest::class)->findOneBy([
            'sessionId' => $sessionId,
            'slotName' => $slotName,
            'status' => DepartureRequest::STATUS_OPEN,
        ]);
    }

    public function findOpenBySession(string $sessionId): array
    {
        return $this->entityManager->getRepository(DepartureRequest::class)->findBy(
            ['sessionId' => $sessionId, 'status' => DepartureRequest::STATUS_OPEN],
            ['createdAt' => 'ASC'],
        );
    }

    public function existsSince(string $sessionId, string $slotName, \DateTimeImmutable $since): bool
    {
        $qb = $this->entityManager->getConnection()->createQueryBuilder();
        $found = $qb->select('1')
            ->from('departure_request')
            ->where('session_id = :session')
            ->andWhere('slot_name = :slot')
            ->andWhere('created_at >= :since')
            ->setParameter('session', $sessionId)
            ->setParameter('slot', $slotName)
            ->setParameter('since', $since, Types::DATETIMETZ_IMMUTABLE)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return false !== $found;
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
