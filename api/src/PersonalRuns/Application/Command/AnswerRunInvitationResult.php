<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

final readonly class AnswerRunInvitationResult
{
    public function __construct(
        public AnswerRunInvitationOutcome $outcome,
        public ?string $runId = null,
    ) {
    }
}
