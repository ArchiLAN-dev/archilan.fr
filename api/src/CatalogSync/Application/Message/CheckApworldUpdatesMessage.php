<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Message;

/**
 * Check every GitHub-tracked apworld for a newer release (story 38.5).
 *
 * Without arguments, the scheduled nightly pass: it checks, then submits what it found as candidates. Story
 * 38.18: the catalogue page's button queues one with the pass it recorded (`runId`) and `submit: false`, so a
 * click only ever checks, as before, but on the worker instead of in a request PHP cuts at 30 seconds.
 */
final readonly class CheckApworldUpdatesMessage
{
    public function __construct(
        public ?string $runId = null,
        public bool $submit = true,
    ) {
    }
}
