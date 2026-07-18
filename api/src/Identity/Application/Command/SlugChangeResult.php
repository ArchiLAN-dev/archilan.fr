<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

final readonly class SlugChangeResult
{
    public function __construct(
        public string $slug,
    ) {
    }
}
