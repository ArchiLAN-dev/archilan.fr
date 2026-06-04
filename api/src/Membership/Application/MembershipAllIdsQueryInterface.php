<?php

declare(strict_types=1);

namespace App\Membership\Application;

interface MembershipAllIdsQueryInterface
{
    /**
     * @return list<string>
     */
    public function execute(): array;
}
