<?php

declare(strict_types=1);

namespace App\Trackers\Application\Query;

/** A pack offered to players for a game (story 54.2): what the tracker needs to load it, and its credits. */
final readonly class PublicTrackerPackView
{
    /**
     * @param array{id: string, packageVersion: ?string, rootPath: string, sizeBytes: int, sha256: string,
     *     variants: list<array{uid: string, displayName: string, flags: list<string>}>} $version
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $author,
        public ?string $licenseSpdx,
        public string $sourceUrl,
        public bool $isDefault,
        public bool $hasApVariant,
        public ?string $defaultVariantUid,
        public array $version,
    ) {
    }
}
