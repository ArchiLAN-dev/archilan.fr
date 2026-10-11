<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

interface PlayerHistoryQueryInterface
{
    /**
     * Every finished run of the player; `is_public` says whether a visitor may see it (story 32.22): a private
     * run once its recap is published, a public event, a weekly.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchForUser(string $userId): array;
}
