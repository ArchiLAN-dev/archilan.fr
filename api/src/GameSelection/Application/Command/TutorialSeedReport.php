<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

final readonly class TutorialSeedReport
{
    public function __construct(
        public int $processed,
        public int $seeded,
    ) {
    }
}
