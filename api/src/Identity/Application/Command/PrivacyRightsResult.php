<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

final readonly class PrivacyRightsResult
{
    public function __construct(
        public string $id,
        public string $rightType,
        public string $status,
        public string $handlingMode,
        public string $submittedAt,
    ) {
    }
}
