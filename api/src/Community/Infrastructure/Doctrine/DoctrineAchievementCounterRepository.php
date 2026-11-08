<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Entity\AchievementCounter;
use App\Community\Domain\Repository\AchievementCounterRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAchievementCounterRepository implements AchievementCounterRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function all(): array
    {
        /** @var list<AchievementCounter> $result */
        $result = $this->entityManager->getRepository(AchievementCounter::class)->findBy([], ['label' => 'ASC']);

        return $result;
    }

    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<AchievementCounter> $result */
        $result = $this->entityManager->getRepository(AchievementCounter::class)->findBy(['id' => $ids], ['label' => 'ASC']);

        return $result;
    }

    public function findById(string $id): ?AchievementCounter
    {
        return $this->entityManager->find(AchievementCounter::class, $id);
    }

    public function save(AchievementCounter $counter): void
    {
        $this->entityManager->persist($counter);
        $this->entityManager->flush();
    }

    public function remove(AchievementCounter $counter): void
    {
        $this->entityManager->remove($counter);
        $this->entityManager->flush();
    }
}
