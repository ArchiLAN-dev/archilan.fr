<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure\Doctrine;

use App\GameSelection\Domain\Entity\GameFollow;
use App\GameSelection\Domain\Repository\GameFollowRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineGameFollowRepository implements GameFollowRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $userId, string $gameId): ?GameFollow
    {
        return $this->entityManager->getRepository(GameFollow::class)->findOneBy(['userId' => $userId, 'gameId' => $gameId]);
    }

    public function followerIds(string $gameId): array
    {
        $ids = [];
        foreach ($this->entityManager->getRepository(GameFollow::class)->findBy(['gameId' => $gameId]) as $follow) {
            $ids[] = $follow->getUserId();
        }

        return $ids;
    }

    public function save(GameFollow $follow): void
    {
        $this->entityManager->persist($follow);
        $this->entityManager->flush();
    }

    public function remove(GameFollow $follow): void
    {
        $this->entityManager->remove($follow);
        $this->entityManager->flush();
    }

    public function deleteByGameId(string $gameId): void
    {
        $this->removeAll(['gameId' => $gameId]);
    }

    public function deleteByUserId(string $userId): void
    {
        $this->removeAll(['userId' => $userId]);
    }

    /** @param array<string, string> $criteria */
    private function removeAll(array $criteria): void
    {
        foreach ($this->entityManager->getRepository(GameFollow::class)->findBy($criteria) as $follow) {
            $this->entityManager->remove($follow);
        }
        $this->entityManager->flush();
    }
}
