<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

final readonly class InviteFriendsToRunResult
{
    /**
     * @param list<string> $invited the friends invited (or invited again)
     * @param list<string> $skipped the ids left out: already in, already invited, declined lately, not a friend
     */
    public function __construct(
        public InviteFriendsToRunOutcome $outcome,
        public array $invited = [],
        public array $skipped = [],
    ) {
    }
}
