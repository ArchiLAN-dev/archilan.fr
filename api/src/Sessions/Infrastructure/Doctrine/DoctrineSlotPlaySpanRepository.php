<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Doctrine;

use App\Sessions\Domain\Entity\SlotPlaySpan;
use App\Sessions\Domain\Repository\SlotPlaySpanRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSlotPlaySpanRepository implements SlotPlaySpanRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findLatest(string $sessionId, string $slotName): ?SlotPlaySpan
    {
        return $this->entityManager->getRepository(SlotPlaySpan::class)->findOneBy(
            ['sessionId' => $sessionId, 'slotName' => $slotName],
            ['lastSeenAt' => 'DESC'],
        );
    }

    public function playedSecondsBySlotName(string $sessionId): array
    {
        $seconds = [];
        foreach ($this->entityManager->getRepository(SlotPlaySpan::class)->findBy(['sessionId' => $sessionId]) as $span) {
            $name = $span->getSlotName();
            $seconds[$name] = ($seconds[$name] ?? 0) + $span->durationSeconds();
        }

        return $seconds;
    }

    public function add(SlotPlaySpan $span): void
    {
        $this->entityManager->persist($span);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
