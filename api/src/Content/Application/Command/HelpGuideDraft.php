<?php

declare(strict_types=1);

namespace App\Content\Application\Command;

/** What the admin writes for a help guide (story 53.5). */
final readonly class HelpGuideDraft
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $summary,
        public string $body,
        public bool $published,
    ) {
    }
}
