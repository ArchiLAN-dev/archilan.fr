<?php

declare(strict_types=1);

namespace App\Community\Application;

interface CommunityUserIdsQueryInterface
{
    /**
     * @return list<string> ids of all non-deleted users (recompute candidates)
     */
    public function allUserIds(): array;
}
