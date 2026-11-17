<?php

declare(strict_types=1);

namespace App\Content\Application\Command;

/** What an admin types for a tracked link (story 55.1). The code is read only on creation. */
final readonly class TrackedLinkDraft
{
    public function __construct(
        public string $code,
        public string $label,
        public string $target,
        public bool $active,
    ) {
    }
}
