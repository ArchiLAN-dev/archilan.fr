<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Domain\AchievementDefinition;
use App\Community\Domain\AchievementDefinitionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAchievementDefinitionRepository implements AchievementDefinitionRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function allActive(): array
    {
        return $this->ordered(['active' => true]);
    }

    public function all(): array
    {
        return $this->ordered([]);
    }

    public function findById(string $id): ?AchievementDefinition
    {
        return $this->entityManager->find(AchievementDefinition::class, $id);
    }

    public function existsByKey(string $key): bool
    {
        return null !== $this->entityManager->getRepository(AchievementDefinition::class)->findOneBy(['key' => $key]);
    }

    public function maxPosition(): int
    {
        $qb = $this->entityManager->getRepository(AchievementDefinition::class)->createQueryBuilder('d');
        $max = $qb->select('MAX(d.position)')->getQuery()->getSingleScalarResult();

        return is_numeric($max) ? (int) $max : -1;
    }

    public function save(AchievementDefinition $definition): void
    {
        $this->entityManager->persist($definition);
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return list<AchievementDefinition>
     */
    private function ordered(array $criteria): array
    {
        /** @var list<AchievementDefinition> $result */
        $result = $this->entityManager->getRepository(AchievementDefinition::class)
            ->findBy($criteria, ['position' => 'ASC']);

        return $result;
    }
}
