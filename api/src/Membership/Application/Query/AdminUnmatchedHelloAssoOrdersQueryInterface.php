<?php

declare(strict_types=1);

namespace App\Membership\Application\Query;

interface AdminUnmatchedHelloAssoOrdersQueryInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array;
}
