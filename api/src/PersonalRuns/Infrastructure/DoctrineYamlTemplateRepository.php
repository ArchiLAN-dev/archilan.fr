<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure;

use App\PersonalRuns\Domain\YamlTemplate;
use App\PersonalRuns\Domain\YamlTemplateRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineYamlTemplateRepository implements YamlTemplateRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?YamlTemplate
    {
        return $this->entityManager->find(YamlTemplate::class, $id);
    }

    public function findByUserAndGame(string $userId, string $gameId): array
    {
        /* @var list<YamlTemplate> */
        return $this->entityManager->getRepository(YamlTemplate::class)->findBy(
            ['userId' => $userId, 'gameId' => $gameId],
            ['updatedAt' => 'DESC', 'id' => 'DESC'],
        );
    }

    public function existsByUserGameName(string $userId, string $gameId, string $name, ?string $excludeId = null): bool
    {
        $criteria = ['userId' => $userId, 'gameId' => $gameId, 'name' => $name];
        $matches = $this->entityManager->getRepository(YamlTemplate::class)->findBy($criteria);

        foreach ($matches as $match) {
            if (null === $excludeId || $match->getId() !== $excludeId) {
                return true;
            }
        }

        return false;
    }

    public function save(YamlTemplate $template): void
    {
        $this->entityManager->persist($template);
        $this->entityManager->flush();
    }

    public function delete(YamlTemplate $template): void
    {
        $this->entityManager->remove($template);
        $this->entityManager->flush();
    }

    public function deleteByUserId(string $userId): void
    {
        foreach ($this->entityManager->getRepository(YamlTemplate::class)->findBy(['userId' => $userId]) as $template) {
            $this->entityManager->remove($template);
        }
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
