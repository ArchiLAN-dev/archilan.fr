<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Doctrine;

use App\Content\Domain\Entity\HelpGuide;
use App\Content\Domain\Repository\HelpGuideRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineHelpGuideRepository implements HelpGuideRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $id): ?HelpGuide
    {
        return $this->entityManager->find(HelpGuide::class, $id);
    }

    public function findBySlug(string $slug): ?HelpGuide
    {
        return $this->entityManager->getRepository(HelpGuide::class)->findOneBy(['slug' => $slug]);
    }

    public function all(): array
    {
        return $this->entityManager->getRepository(HelpGuide::class)->findBy([], ['position' => 'ASC', 'createdAt' => 'ASC']);
    }

    public function save(HelpGuide $guide): void
    {
        $this->entityManager->persist($guide);
        $this->entityManager->flush();
    }

    public function saveAll(array $guides): void
    {
        foreach ($guides as $guide) {
            $this->entityManager->persist($guide);
        }
        $this->entityManager->flush();
    }

    public function remove(HelpGuide $guide): void
    {
        $this->entityManager->remove($guide);
        $this->entityManager->flush();
    }
}
