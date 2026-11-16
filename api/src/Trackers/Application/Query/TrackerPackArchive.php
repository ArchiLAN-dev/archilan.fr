<?php

declare(strict_types=1);

namespace App\Trackers\Application\Query;

/** The zip of a pack version, as stored (story 54.2). */
final readonly class TrackerPackArchive
{
    public function __construct(
        public string $contents,
        public string $sha256,
        public string $filename,
    ) {
    }
}
