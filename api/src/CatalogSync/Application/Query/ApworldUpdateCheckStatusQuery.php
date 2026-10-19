<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Query;

use App\CatalogSync\Domain\Repository\ApworldUpdateCheckRunRepositoryInterface;

/**
 * Story 38.18: the last pass checking the apworlds for updates, from the button or the night, so the catalogue
 * page can follow it and find it again after a reload.
 */
final readonly class ApworldUpdateCheckStatusQuery
{
    public function __construct(private ApworldUpdateCheckRunRepositoryInterface $runs)
    {
    }

    public function latest(): ?ApworldUpdateCheckRunView
    {
        $run = $this->runs->findLatest();

        return null === $run ? null : ApworldUpdateCheckRunView::of($run);
    }
}
