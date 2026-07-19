<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Repository;

use App\GameSelection\Domain\Entity\ArchipelagoClientInfo;

interface ArchipelagoClientInfoRepositoryInterface
{
    public function get(): ?ArchipelagoClientInfo;

    public function save(ArchipelagoClientInfo $info): void;
}
