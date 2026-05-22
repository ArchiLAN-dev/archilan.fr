<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSessionRepository implements SessionRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?Session
    {
        return $this->entityManager->find(Session::class, $id);
    }

    public function findByEventId(string $eventId): array
    {
        /* @var list<Session> */
        return $this->entityManager->getRepository(Session::class)->findBy(
            ['eventId' => $eventId],
            ['createdAt' => 'DESC'],
        );
    }

    public function findByStatus(string $status): array
    {
        /* @var list<Session> */
        return $this->entityManager->getRepository(Session::class)->findBy(['status' => $status]);
    }

    public function findByStatuses(array $statuses): array
    {
        if ([] === $statuses) {
            return [];
        }

        /* @var list<Session> */
        return $this->entityManager->getRepository(Session::class)->findBy(['status' => $statuses]);
    }

    public function findMostRecentFinishedByEventId(string $eventId): ?Session
    {
        /* @var Session|null */
        return $this->entityManager->getRepository(Session::class)->findOneBy(
            ['eventId' => $eventId, 'status' => Session::STATUS_FINISHED],
            ['finishedAt' => 'DESC'],
        );
    }

    public function persist(Session $session): void
    {
        $this->entityManager->persist($session);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
