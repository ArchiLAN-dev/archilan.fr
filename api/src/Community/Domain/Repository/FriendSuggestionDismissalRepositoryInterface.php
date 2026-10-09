<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\FriendSuggestionDismissal;

interface FriendSuggestionDismissalRepositoryInterface
{
    public function exists(string $userId, string $dismissedUserId): bool;

    public function save(FriendSuggestionDismissal $dismissal): void;
}
