<?php

declare(strict_types=1);

namespace App\Trackers\Application\Query;

/** A game's pack as the admin sees it (stories 54.1, 54.2). */
final readonly class AdminTrackerPackView
{
    /** @param list<array{uid: string, displayName: string, flags: list<string>}> $variants */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $author,
        public string $packageUid,
        public ?string $gameName,
        public string $sourceUrl,
        public ?string $licenseSpdx,
        public bool $hasApVariant,
        public string $versionId,
        public ?string $packageVersion,
        public ?string $gitTag,
        public int $sizeBytes,
        public array $variants,
        public ?string $minPopTrackerVersion,
        public string $importedAt,
        public bool $enabled,
        public bool $isDefault,
        public ?string $defaultVariantUid,
        public bool $licenseAcknowledged,
    ) {
    }
}
