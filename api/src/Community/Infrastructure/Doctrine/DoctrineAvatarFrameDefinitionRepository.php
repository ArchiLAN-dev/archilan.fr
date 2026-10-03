<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Entity\AvatarFrameDefinition;
use App\Community\Domain\Repository\AvatarFrameDefinitionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAvatarFrameDefinitionRepository implements AvatarFrameDefinitionRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $key): ?AvatarFrameDefinition
    {
        return $this->entityManager->find(AvatarFrameDefinition::class, $key);
    }

    public function all(): array
    {
        return $this->entityManager->getRepository(AvatarFrameDefinition::class)->findBy([], ['position' => 'ASC', 'key' => 'ASC']);
    }

    public function save(AvatarFrameDefinition $definition): void
    {
        $this->entityManager->persist($definition);
        $this->entityManager->flush();
    }
}
