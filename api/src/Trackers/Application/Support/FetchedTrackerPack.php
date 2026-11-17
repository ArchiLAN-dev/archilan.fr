<?php

declare(strict_types=1);

namespace App\Trackers\Application\Support;

/** A pack zip downloaded from GitHub, with where it came from (story 54.1). */
final readonly class FetchedTrackerPack
{
    public function __construct(
        public string $archive,
        public string $downloadUrl,
        public ?string $gitTag,
        public ?string $licenseSpdx,
    ) {
    }
}
