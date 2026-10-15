<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Message;

final readonly class LaunchPersonalRunJob
{
    /**
     * @param list<string> $requiredFileIds files the launcher provided, held by the orchestrator for this
     *                                      launch's generation (story 44.2) - ids only, never their content
     */
    public function __construct(
        public string $personalRunId,
        public array $requiredFileIds = [],
    ) {
    }
}
