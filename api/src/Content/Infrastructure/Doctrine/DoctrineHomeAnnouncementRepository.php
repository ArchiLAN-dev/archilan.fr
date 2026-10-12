<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Doctrine;

use App\Content\Domain\Entity\HomeAnnouncement;
use App\Content\Domain\Repository\HomeAnnouncementRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineHomeAnnouncementRepository implements HomeAnnouncementRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $id): ?HomeAnnouncement
    {
        return $this->entityManager->find(HomeAnnouncement::class, $id);
    }

    public function all(): array
    {
        return $this->entityManager->getRepository(HomeAnnouncement::class)->findBy([], ['startsAt' => 'DESC']);
    }

    public function save(HomeAnnouncement $announcement): void
    {
        $this->entityManager->persist($announcement);
        $this->entityManager->flush();
    }

    public function remove(HomeAnnouncement $announcement): void
    {
        $this->entityManager->remove($announcement);
        $this->entityManager->flush();
    }
}
