<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Entity\AchievementCollection;
use App\Community\Domain\Entity\AchievementCollectionCompletion;
use App\Community\Domain\Repository\AchievementCollectionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAchievementCollectionRepository implements AchievementCollectionRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function all(): array
    {
        /** @var list<AchievementCollection> $result */
        $result = $this->entityManager->getRepository(AchievementCollection::class)->findBy([], ['position' => 'ASC']);

        return $result;
    }

    public function findById(string $id): ?AchievementCollection
    {
        return $this->entityManager->find(AchievementCollection::class, $id);
    }

    public function maxPosition(): int
    {
        $max = $this->entityManager->getRepository(AchievementCollection::class)->createQueryBuilder('c')
            ->select('MAX(c.position)')->getQuery()->getSingleScalarResult();

        return is_numeric($max) ? (int) $max : -1;
    }

    public function save(AchievementCollection $collection): void
    {
        $this->entityManager->persist($collection);
        $this->entityManager->flush();
    }

    public function remove(AchievementCollection $collection): void
    {
        // The completions go with it (the service already moved its achievements back to « Autres succès »).
        $this->entityManager->getConnection()->createQueryBuilder()
            ->delete('community_achievement_collection_completion')
            ->where('collection_id = :id')
            ->setParameter('id', $collection->getId())
            ->executeStatement();
        $this->entityManager->remove($collection);
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function completedIds(string $userId): array
    {
        return array_map(
            static fn (AchievementCollectionCompletion $c): string => $c->getCollectionId(),
            $this->entityManager->getRepository(AchievementCollectionCompletion::class)->findBy(['userId' => $userId]),
        );
    }

    public function saveCompletion(AchievementCollectionCompletion $completion): void
    {
        $this->entityManager->persist($completion);
        $this->entityManager->flush();
    }
}
