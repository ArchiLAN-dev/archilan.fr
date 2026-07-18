<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Command;

/**
 * Admin-facing view of a weekly template, returned by the create/update template commands
 * ({@see AdminCreateWeeklyTemplate}, {@see AdminUpdateWeeklyTemplate}). The controller serializes it verbatim
 * as the `data` payload.
 */
final readonly class WeeklyTemplateResult
{
    public function __construct(
        public string $id,
        public ?string $name,
        public string $gameId,
        public string $gameName,
        public string $yamlConfig,
        public ?int $maxAttempts,
        public bool $isActive,
    ) {
    }
}
