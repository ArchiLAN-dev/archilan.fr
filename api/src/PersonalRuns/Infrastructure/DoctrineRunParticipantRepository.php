<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure;

use App\PersonalRuns\Domain\RunParticipant;
use App\PersonalRuns\Domain\RunParticipantRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRunParticipantRepository implements RunParticipantRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findByRunId(string $runId): array
    {
        /* @var list<RunParticipant> */
        return $this->entityManager->getRepository(RunParticipant::class)->findBy(
            ['runId' => $runId],
            ['joinedAt' => 'ASC'],
        );
    }

    public function findByRunAndUser(string $runId, string $userId): ?RunParticipant
    {
        return $this->entityManager->find(RunParticipant::class, ['runId' => $runId, 'userId' => $userId]);
    }

    public function countByRunId(string $runId): int
    {
        return count($this->findByRunId($runId));
    }

    public function save(RunParticipant $participant): void
    {
        $this->entityManager->persist($participant);
        $this->entityManager->flush();
    }

    public function deleteByRunId(string $runId): void
    {
        foreach ($this->findByRunId($runId) as $participant) {
            $this->entityManager->remove($participant);
        }
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
