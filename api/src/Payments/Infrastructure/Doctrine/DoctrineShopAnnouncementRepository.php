<?php

declare(strict_types=1);

namespace App\Payments\Infrastructure\Doctrine;

use App\Payments\Domain\Entity\ShopAnnouncement;
use App\Payments\Domain\Repository\ShopAnnouncementRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineShopAnnouncementRepository implements ShopAnnouncementRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function current(): ?ShopAnnouncement
    {
        return $this->entityManager->find(ShopAnnouncement::class, ShopAnnouncement::HELLOASSO_SHOP);
    }

    public function save(ShopAnnouncement $announcement): void
    {
        $this->entityManager->persist($announcement);
        $this->entityManager->flush();
    }

    public function remove(ShopAnnouncement $announcement): void
    {
        $this->entityManager->remove($announcement);
        $this->entityManager->flush();
    }
}
