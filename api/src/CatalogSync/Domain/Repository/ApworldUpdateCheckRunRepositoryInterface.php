<?php

declare(strict_types=1);

namespace App\CatalogSync\Domain\Repository;

use App\CatalogSync\Domain\Entity\ApworldUpdateCheckRun;

interface ApworldUpdateCheckRunRepositoryInterface
{
    public function findById(string $id): ?ApworldUpdateCheckRun;

    /** The most recently queued pass, whatever its status. */
    public function findLatest(): ?ApworldUpdateCheckRun;

    /** The pass holding the active slot, queued or running. */
    public function findActive(): ?ApworldUpdateCheckRun;

    /**
     * Records a new pass only if no other pass is active, atomically (the unique active slot): false when
     * another one already holds it. Never throws on that conflict, so the entity manager stays usable.
     */
    public function claim(ApworldUpdateCheckRun $run): bool;

    public function save(ApworldUpdateCheckRun $run): void;
}
