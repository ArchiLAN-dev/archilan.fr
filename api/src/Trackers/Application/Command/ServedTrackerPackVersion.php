<?php

declare(strict_types=1);

namespace App\Trackers\Application\Command;

/** The version a pack now serves, after an update or a way back (story 54.3), or only keeps (story 54.13). */
final readonly class ServedTrackerPackVersion
{
    public function __construct(
        public string $versionId,
        public ?string $packageVersion,
        /** The default variant chosen by an admin is not in this version: players get the first one again. */
        public bool $defaultVariantReset,
        /** Story 54.13: false when an enabled pack only keeps the version, for an admin to try it first. */
        public bool $served = true,
    ) {
    }
}
