<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Support;

final readonly class ApworldVersionInfo
{
    public function __construct(
        public string $latestTag,
        public \DateTimeImmutable $publishedAt,
        public string $releaseUrl,
        public ?string $assetName,
        public ?string $assetDownloadUrl,
        public string $updateStatus,
        public bool $isNewer,
    ) {
    }
}
