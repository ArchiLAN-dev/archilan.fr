<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Command;

final readonly class ApworldUpdateCheckReport
{
    public function __construct(
        public int $checked,
        public bool $rateLimitHit,
    ) {
    }
}
