<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure\Doctrine;

use App\PersonalRuns\Domain\Entity\RunNudge;
use App\PersonalRuns\Domain\Repository\RunNudgeRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRunNudgeRepository implements RunNudgeRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $runId, string $recipientId): ?RunNudge
    {
        return $this->entityManager->getRepository(RunNudge::class)
            ->findOneBy(['runId' => $runId, 'recipientId' => $recipientId]);
    }

    public function findByRunId(string $runId): array
    {
        return $this->entityManager->getRepository(RunNudge::class)->findBy(['runId' => $runId]);
    }

    public function save(RunNudge $nudge): void
    {
        $this->entityManager->persist($nudge);
        $this->entityManager->flush();
    }

    public function deleteByRunId(string $runId): void
    {
        foreach ($this->findByRunId($runId) as $nudge) {
            $this->entityManager->remove($nudge);
        }
        $this->entityManager->flush();
    }
}
