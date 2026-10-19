<?php

declare(strict_types=1);

namespace App\CatalogSync\Infrastructure\Doctrine;

use App\CatalogSync\Domain\Entity\ApworldUpdateCheckRun;
use App\CatalogSync\Domain\Repository\ApworldUpdateCheckRunRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineApworldUpdateCheckRunRepository implements ApworldUpdateCheckRunRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?ApworldUpdateCheckRun
    {
        return $this->entityManager->find(ApworldUpdateCheckRun::class, $id);
    }

    public function findLatest(): ?ApworldUpdateCheckRun
    {
        return $this->entityManager->getRepository(ApworldUpdateCheckRun::class)->findOneBy([], ['queuedAt' => 'DESC']);
    }

    public function findActive(): ?ApworldUpdateCheckRun
    {
        return $this->entityManager->getRepository(ApworldUpdateCheckRun::class)->findOneBy(['activeSlot' => ApworldUpdateCheckRun::ACTIVE]);
    }

    /**
     * A plain insert that skips on the active slot's unique constraint: a failed ORM flush would close the
     * entity manager, which the worker keeps between messages.
     */
    public function claim(ApworldUpdateCheckRun $run): bool
    {
        $inserted = $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO apworld_update_check_run (id, trigger_source, status, active_slot, queued_at, checked, failed, rate_limit_hit, updates_available)
             VALUES (:id, :trigger, :status, :active, :queuedAt, 0, 0, false, 0)
             ON CONFLICT (active_slot) DO NOTHING',
            [
                'id' => $run->getId(),
                'trigger' => $run->getTrigger(),
                'status' => $run->getStatus(),
                'active' => ApworldUpdateCheckRun::ACTIVE,
                'queuedAt' => $run->getQueuedAt()->format('Y-m-d H:i:sP'),
            ],
        );

        return 1 === $inserted;
    }

    public function save(ApworldUpdateCheckRun $run): void
    {
        $this->entityManager->persist($run);
        $this->entityManager->flush();
    }
}
