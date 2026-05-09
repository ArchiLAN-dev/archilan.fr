<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Registrations\Domain\Registration;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RegistrationCounter
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function countConfirmed(string $eventId): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(Registration::class, 'r')
            ->where('r.eventId = :eventId')
            ->andWhere('r.status != :cancelled')
            ->setParameter('eventId', $eventId)
            ->setParameter('cancelled', Registration::STATUS_CANCELLED)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
