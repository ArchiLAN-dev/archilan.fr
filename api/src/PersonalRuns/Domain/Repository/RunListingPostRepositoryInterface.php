<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain\Repository;

use App\PersonalRuns\Domain\Entity\RunListingPost;

interface RunListingPostRepositoryInterface
{
    /** @return array<string, RunListingPost> every post, keyed by run id */
    public function all(): array;

    /** Persists and flushes. */
    public function save(RunListingPost $post): void;
}
