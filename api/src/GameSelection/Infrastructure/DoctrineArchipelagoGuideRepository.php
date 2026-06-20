<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

use App\GameSelection\Domain\ArchipelagoGuide;
use App\GameSelection\Domain\ArchipelagoGuideRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineArchipelagoGuideRepository implements ArchipelagoGuideRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function get(): ?ArchipelagoGuide
    {
        return $this->entityManager->find(ArchipelagoGuide::class, ArchipelagoGuide::SINGLETON_ID);
    }

    public function save(ArchipelagoGuide $guide): void
    {
        $this->entityManager->persist($guide);
        $this->entityManager->flush();
    }
}
