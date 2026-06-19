<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

use App\GameSelection\Domain\GameTutorialContribution;
use App\GameSelection\Domain\GameTutorialContributionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineGameTutorialContributionRepository implements GameTutorialContributionRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(GameTutorialContribution $contribution): void
    {
        $this->entityManager->persist($contribution);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?GameTutorialContribution
    {
        return $this->entityManager->find(GameTutorialContribution::class, $id);
    }

    public function countPendingForGame(string $authorId, string $gameId): int
    {
        return $this->entityManager->getRepository(GameTutorialContribution::class)->count([
            'authorId' => $authorId,
            'gameId' => $gameId,
            'status' => GameTutorialContribution::STATUS_PENDING,
        ]);
    }

    public function countPendingForProposedName(string $authorId, string $proposedGameName): int
    {
        return $this->entityManager->getRepository(GameTutorialContribution::class)->count([
            'authorId' => $authorId,
            'proposedGameName' => $proposedGameName,
            'status' => GameTutorialContribution::STATUS_PENDING,
        ]);
    }
}
