<?php

declare(strict_types=1);

namespace App\GameSelection\Domain;

interface ArchipelagoClientInfoRepositoryInterface
{
    public function get(): ?ArchipelagoClientInfo;

    public function save(ArchipelagoClientInfo $info): void;
}
