<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure\Doctrine;

use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Enum\ApworldCandidateStatus;
use App\GameSelection\Domain\Repository\ApworldCandidateRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineApworldCandidateRepository implements ApworldCandidateRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(ApworldCandidate $candidate): void
    {
        $this->entityManager->persist($candidate);
    }

    public function findById(string $id): ?ApworldCandidate
    {
        return $this->entityManager->find(ApworldCandidate::class, $id);
    }

    public function findTestingForGame(string $gameId): ?ApworldCandidate
    {
        return $this->entityManager->getRepository(ApworldCandidate::class)->findOneBy(
            ['gameId' => $gameId, 'status' => ApworldCandidateStatus::Testing],
            ['submittedAt' => 'DESC'],
        );
    }

    public function findAllTesting(): array
    {
        return $this->entityManager->getRepository(ApworldCandidate::class)->findBy(
            ['status' => ApworldCandidateStatus::Testing],
            ['submittedAt' => 'ASC'],
        );
    }

    public function findLatestForGame(string $gameId): ?ApworldCandidate
    {
        return $this->entityManager->getRepository(ApworldCandidate::class)->findOneBy(
            ['gameId' => $gameId],
            ['submittedAt' => 'DESC'],
        );
    }

    public function hasRejectedVersion(string $gameId, string $versionTag): bool
    {
        return null !== $this->entityManager->getRepository(ApworldCandidate::class)->findOneBy([
            'gameId' => $gameId,
            'versionTag' => $versionTag,
            'status' => ApworldCandidateStatus::Rejected,
        ]);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
