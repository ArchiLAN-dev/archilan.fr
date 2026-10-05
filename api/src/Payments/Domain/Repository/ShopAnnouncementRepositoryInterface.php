<?php

declare(strict_types=1);

namespace App\Payments\Domain\Repository;

use App\Payments\Domain\Entity\ShopAnnouncement;

interface ShopAnnouncementRepositoryInterface
{
    public function current(): ?ShopAnnouncement;

    public function save(ShopAnnouncement $announcement): void;

    public function remove(ShopAnnouncement $announcement): void;
}
