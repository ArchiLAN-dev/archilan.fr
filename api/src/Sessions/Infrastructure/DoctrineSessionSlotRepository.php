<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

use App\Sessions\Domain\SessionSlot;
use App\Sessions\Domain\SessionSlotRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSessionSlotRepository implements SessionSlotRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findBySessionId(string $sessionId): array
    {
        /* @var list<SessionSlot> */
        return $this->entityManager->getRepository(SessionSlot::class)->findBy(
            ['sessionId' => $sessionId],
            ['slotOrder' => 'ASC'],
        );
    }

    public function findByRegistrationAndSession(string $registrationId, string $sessionId): array
    {
        /* @var list<SessionSlot> */
        return $this->entityManager->getRepository(SessionSlot::class)->findBy(
            ['registrationId' => $registrationId, 'sessionId' => $sessionId],
            ['slotOrder' => 'ASC'],
        );
    }

    public function findBySessionAndSlotName(string $sessionId, string $slotName): ?SessionSlot
    {
        /* @var SessionSlot|null */
        return $this->entityManager->getRepository(SessionSlot::class)->findOneBy([
            'sessionId' => $sessionId,
            'slotName' => $slotName,
        ]);
    }

    public function persist(SessionSlot $slot): void
    {
        $this->entityManager->persist($slot);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
