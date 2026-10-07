<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Entity\ProfileTitleDefinition;
use App\Community\Domain\Repository\ProfileTitleDefinitionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineProfileTitleDefinitionRepository implements ProfileTitleDefinitionRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $key): ?ProfileTitleDefinition
    {
        return $this->entityManager->find(ProfileTitleDefinition::class, $key);
    }

    public function all(): array
    {
        return $this->entityManager->getRepository(ProfileTitleDefinition::class)->findBy([], ['position' => 'ASC', 'key' => 'ASC']);
    }

    public function save(ProfileTitleDefinition $definition): void
    {
        $this->entityManager->persist($definition);
        $this->entityManager->flush();
    }
}
