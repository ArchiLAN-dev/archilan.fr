<?php

declare(strict_types=1);

namespace App\GameSelection\Domain;

interface ArchipelagoGuideRepositoryInterface
{
    public function get(): ?ArchipelagoGuide;

    public function save(ArchipelagoGuide $guide): void;
}
