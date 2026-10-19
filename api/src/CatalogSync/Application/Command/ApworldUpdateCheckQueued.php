<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Command;

use App\CatalogSync\Application\Query\ApworldUpdateCheckRunView;

/**
 * Story 38.18: what the button got. `queued` false means a pass was already queued or running: `run` is that
 * one, the page follows it instead of starting another.
 */
final readonly class ApworldUpdateCheckQueued
{
    public function __construct(
        public bool $queued,
        public ?ApworldUpdateCheckRunView $run,
    ) {
    }
}
