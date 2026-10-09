<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Query;

/**
 * The friendships a run invitation leans on (story 43.1), read from the community tables behind an interface so
 * PersonalRuns does not import the Community domain.
 */
interface RunInviteFriendsQueryInterface
{
    /**
     * Whether the inviter may invite the invitee by name: an accepted friendship, and no block either way.
     */
    public function canInvite(string $inviterId, string $inviteeId): bool;
}
