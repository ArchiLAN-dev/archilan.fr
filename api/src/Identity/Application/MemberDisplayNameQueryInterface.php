<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * Resolves a member's community display-name override (community_profile.display_name) for the authenticated
 * user payload, so the pseudo shown across the app is the community one. Identity defines the port; a
 * Community Infrastructure adapter implements it (Community → Identity, the natural direction). Returns null
 * when no override is set, so the caller falls back to the account display name.
 */
interface MemberDisplayNameQueryInterface
{
    public function displayNameFor(string $userId): ?string;
}
