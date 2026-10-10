<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Doctrine;

use App\Sessions\Domain\Entity\SlotBlockRelease;
use App\Sessions\Domain\Repository\SlotBlockReleaseRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSlotBlockReleaseRepository implements SlotBlockReleaseRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(SlotBlockRelease $release): void
    {
        $this->entityManager->persist($release);
    }
}
