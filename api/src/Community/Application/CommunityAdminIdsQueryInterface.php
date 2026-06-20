<?php

declare(strict_types=1);

namespace App\Community\Application;

/**
 * Returns the user ids of admins, to route moderation notifications to them (story 30.28). ROLE_ADMIN is a
 * stable role (unlike the stale-prone ROLE_MEMBER), and this is notification routing, not access control,
 * so reading it here is fine (AC-M3).
 */
interface CommunityAdminIdsQueryInterface
{
    /**
     * @return list<string>
     */
    public function adminUserIds(): array;
}
