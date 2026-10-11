<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

final readonly class NudgeRunParticipantResult
{
    public function __construct(
        public NudgeRunParticipantOutcome $outcome,
        public ?\DateTimeImmutable $lastNudgedAt = null,
        /** On AlreadyNudged: whole hours since that nudge. */
        public ?int $hoursAgo = null,
    ) {
    }
}
