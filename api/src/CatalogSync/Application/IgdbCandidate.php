<?php

declare(strict_types=1);

namespace App\CatalogSync\Application;

final readonly class IgdbCandidate
{
    public function __construct(
        public int $igdbId,
        public string $name,
        public ?string $summary,
        public ?string $coverUrl,
    ) {
    }
}
