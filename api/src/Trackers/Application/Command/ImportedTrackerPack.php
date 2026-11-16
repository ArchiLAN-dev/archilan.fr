<?php

declare(strict_types=1);

namespace App\Trackers\Application\Command;

/** The pack {@see ImportTrackerPack} just imported. */
final readonly class ImportedTrackerPack
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $packageVersion,
        public bool $hasApVariant,
    ) {
    }
}
