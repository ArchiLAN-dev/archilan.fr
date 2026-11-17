<?php

declare(strict_types=1);

namespace App\Trackers\Application\Command;

/** What an admin changes on a pack (story 54.2). A null field is left as it is. */
final readonly class TrackerPackChanges
{
    public function __construct(
        public ?bool $enabled = null,
        /** Only `true` is meaningful: a game's default moves by choosing another pack, or by disabling this one. */
        public ?bool $makeDefault = null,
        /** Whether the request names a default variant at all; `defaultVariantUid` null then means the first one. */
        public bool $setsDefaultVariant = false,
        public ?string $defaultVariantUid = null,
        public ?bool $licenseAcknowledged = null,
    ) {
    }
}
