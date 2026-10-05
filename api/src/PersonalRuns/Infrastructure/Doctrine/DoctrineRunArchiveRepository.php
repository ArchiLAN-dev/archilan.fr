<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure\Doctrine;

use App\PersonalRuns\Domain\Entity\RunArchive;
use App\PersonalRuns\Domain\Repository\RunArchiveRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRunArchiveRepository implements RunArchiveRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $runId, string $userId): ?RunArchive
    {
        return $this->entityManager->find(RunArchive::class, ['runId' => $runId, 'userId' => $userId]);
    }

    public function archivedRunIds(string $userId): array
    {
        return array_map(
            static fn (RunArchive $archive): string => $archive->getRunId(),
            $this->entityManager->getRepository(RunArchive::class)->findBy(['userId' => $userId]),
        );
    }

    public function save(RunArchive $archive): void
    {
        $this->entityManager->persist($archive);
        $this->entityManager->flush();
    }

    public function remove(RunArchive $archive): void
    {
        $this->entityManager->remove($archive);
        $this->entityManager->flush();
    }

    public function deleteByRunId(string $runId): void
    {
        foreach ($this->entityManager->getRepository(RunArchive::class)->findBy(['runId' => $runId]) as $archive) {
            $this->entityManager->remove($archive);
        }
        $this->entityManager->flush();
    }
}
