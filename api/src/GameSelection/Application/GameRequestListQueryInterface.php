<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

interface GameRequestListQueryInterface
{
    /**
     * @return list<array{normalizedName: string, displayName: string, voteCount: int, hasVoted: bool}>
     */
    public function list(?string $userId): array;
}
