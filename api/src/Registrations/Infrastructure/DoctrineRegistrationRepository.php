<?php

declare(strict_types=1);

namespace App\Registrations\Infrastructure;

use App\Events\Domain\Event;
use App\Registrations\Domain\Registration;
use App\Registrations\Domain\RegistrationRepositoryInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRegistrationRepository implements RegistrationRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?Registration
    {
        return $this->entityManager->find(Registration::class, $id);
    }

    public function findByEventAndUser(string $eventId, string $userId): ?Registration
    {
        /* @var Registration|null */
        return $this->entityManager->getRepository(Registration::class)->findOneBy([
            'eventId' => $eventId,
            'userId' => $userId,
        ]);
    }

    public function findBy(array $criteria, array $orderBy = [], ?int $limit = null): array
    {
        /* @var list<Registration> */
        return $this->entityManager->getRepository(Registration::class)->findBy($criteria, $orderBy, $limit);
    }

    public function save(Registration $registration): void
    {
        $this->entityManager->persist($registration);
        $this->entityManager->flush();
    }

    public function persist(Registration $registration): void
    {
        $this->entityManager->persist($registration);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function findEventWithExclusiveLock(string $eventId): ?Event
    {
        $event = $this->entityManager->find(Event::class, $eventId, LockMode::PESSIMISTIC_WRITE);
        if (!$event instanceof Event) {
            return null;
        }
        $this->entityManager->refresh($event, LockMode::PESSIMISTIC_WRITE);

        return $event;
    }

    public function beginTransaction(): void
    {
        $this->entityManager->getConnection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->entityManager->getConnection()->commit();
    }

    public function rollBack(): void
    {
        $this->entityManager->getConnection()->rollBack();
    }
}
