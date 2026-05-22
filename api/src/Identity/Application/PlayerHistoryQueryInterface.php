<?php

declare(strict_types=1);

namespace App\Identity\Application;

interface PlayerHistoryQueryInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function fetchForUser(string $userId): array;
}
