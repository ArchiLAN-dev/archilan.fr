<?php

declare(strict_types=1);

namespace App\Membership\Application\Query;

interface MembershipAllIdsQueryInterface
{
    /**
     * @return list<string>
     */
    public function execute(): array;
}
