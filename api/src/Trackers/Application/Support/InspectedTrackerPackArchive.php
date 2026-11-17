<?php

declare(strict_types=1);

namespace App\Trackers\Application\Support;

/** What the archive inspector found in a pack zip (story 54.1). */
final readonly class InspectedTrackerPackArchive
{
    public function __construct(
        /** The folder holding `manifest.json`, empty when it sits at the root of the zip. */
        public string $rootPath,
        public string $manifest,
    ) {
    }
}
