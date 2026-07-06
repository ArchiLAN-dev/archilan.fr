<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Repository;

use App\GameSelection\Domain\Entity\ArchipelagoGuide;

interface ArchipelagoGuideRepositoryInterface
{
    public function get(): ?ArchipelagoGuide;

    public function save(ArchipelagoGuide $guide): void;
}
