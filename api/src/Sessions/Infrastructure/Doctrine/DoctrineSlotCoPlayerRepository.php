<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Doctrine;

use App\Sessions\Domain\Entity\SlotCoPlayer;
use App\Sessions\Domain\Repository\SlotCoPlayerRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSlotCoPlayerRepository implements SlotCoPlayerRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findBySlotIds(array $slotIds): array
    {
        if ([] === $slotIds) {
            return [];
        }

        /* @var list<SlotCoPlayer> */
        return $this->entityManager->getRepository(SlotCoPlayer::class)->findBy(
            ['slotId' => $slotIds],
            ['addedAt' => 'ASC'],
        );
    }

    public function findSlotIdsForUser(string $userId): array
    {
        /** @var list<SlotCoPlayer> $rows */
        $rows = $this->entityManager->getRepository(SlotCoPlayer::class)->findBy(['userId' => $userId]);

        return array_values(array_unique(array_map(
            static fn (SlotCoPlayer $coPlayer): string => $coPlayer->getSlotId(),
            $rows,
        )));
    }

    public function persist(SlotCoPlayer $coPlayer): void
    {
        $this->entityManager->persist($coPlayer);
    }

    public function remove(SlotCoPlayer $coPlayer): void
    {
        $this->entityManager->remove($coPlayer);
    }

    public function deleteBySlotId(string $slotId): void
    {
        foreach ($this->entityManager->getRepository(SlotCoPlayer::class)->findBy(['slotId' => $slotId]) as $coPlayer) {
            $this->entityManager->remove($coPlayer);
        }
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
