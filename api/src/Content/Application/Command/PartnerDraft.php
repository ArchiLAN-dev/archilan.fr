<?php

declare(strict_types=1);

namespace App\Content\Application\Command;

/** What the admin writes for a partner (story 45.1). */
final readonly class PartnerDraft
{
    public function __construct(
        public string $name,
        public string $description,
        public string $url,
        public bool $active,
    ) {
    }
}
