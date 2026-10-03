<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Entity\ProfileBannerDefinition;
use App\Community\Domain\Repository\ProfileBannerDefinitionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineProfileBannerDefinitionRepository implements ProfileBannerDefinitionRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $key): ?ProfileBannerDefinition
    {
        return $this->entityManager->find(ProfileBannerDefinition::class, $key);
    }

    public function all(): array
    {
        return $this->entityManager->getRepository(ProfileBannerDefinition::class)->findBy([], ['position' => 'ASC', 'key' => 'ASC']);
    }

    public function save(ProfileBannerDefinition $definition): void
    {
        $this->entityManager->persist($definition);
        $this->entityManager->flush();
    }
}
