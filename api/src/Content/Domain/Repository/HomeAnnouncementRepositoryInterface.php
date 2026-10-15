<?php

declare(strict_types=1);

namespace App\Content\Domain\Repository;

use App\Content\Domain\Entity\HomeAnnouncement;

interface HomeAnnouncementRepositoryInterface
{
    public function find(string $id): ?HomeAnnouncement;

    /** @return list<HomeAnnouncement> newest window first */
    public function all(): array;

    public function save(HomeAnnouncement $announcement): void;

    public function remove(HomeAnnouncement $announcement): void;
}
