<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Doctrine;

use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Repository\SessionFeedEventRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSessionFeedEventRepository implements SessionFeedEventRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(SessionFeedEvent $event): void
    {
        $this->entityManager->persist($event);
        $this->entityManager->flush();
    }

    public function findBySessionId(string $sessionId): array
    {
        /** @var list<SessionFeedEvent> $events */
        $events = $this->entityManager->getRepository(SessionFeedEvent::class)
            ->findBy(['sessionId' => $sessionId], ['occurredAt' => 'ASC']);

        return $events;
    }
}
