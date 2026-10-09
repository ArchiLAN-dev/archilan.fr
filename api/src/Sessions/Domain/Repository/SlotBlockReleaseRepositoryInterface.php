<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Repository;

use App\Sessions\Domain\Entity\SlotBlockRelease;

interface SlotBlockReleaseRepositoryInterface
{
    /** Persisted with the episodes' flush (one unit of work, story 43.10). */
    public function add(SlotBlockRelease $release): void;
}
