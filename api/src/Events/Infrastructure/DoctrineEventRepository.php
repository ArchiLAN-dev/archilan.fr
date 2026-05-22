<?php

declare(strict_types=1);

namespace App\Events\Infrastructure;

use App\Events\Domain\Event;
use App\Events\Domain\EventRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEventRepository implements EventRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?Event
    {
        return $this->entityManager->find(Event::class, $id);
    }

    public function findByStatuses(array $statuses, int $limit = 500): array
    {
        /* @var list<Event> */
        return $this->entityManager->getRepository(Event::class)->findBy(['status' => $statuses], ['startsAt' => 'ASC'], $limit);
    }

    public function findAllSortedByStartsAt(int $limit = 500): array
    {
        /* @var list<Event> */
        return $this->entityManager->getRepository(Event::class)->findBy([], ['startsAt' => 'ASC'], $limit);
    }

    public function findByStatus(string $status, int $limit = 500): array
    {
        /* @var list<Event> */
        return $this->entityManager->getRepository(Event::class)->findBy(['status' => $status], ['startsAt' => 'ASC'], $limit);
    }

    public function save(Event $event): void
    {
        $this->entityManager->persist($event);
        $this->entityManager->flush();
    }
}
