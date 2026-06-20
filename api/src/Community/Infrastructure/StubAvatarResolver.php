<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Application\AvatarResolverInterface;

/**
 * Deterministic test double: returns a stable fake URL per user so the caching pipeline can be asserted
 * without hitting Discord/Steam. Registered only in `when@test`.
 */
final readonly class StubAvatarResolver implements AvatarResolverInterface
{
    public function resolve(string $userId): ?string
    {
        if ('' === $userId) {
            return null;
        }

        return 'https://cdn.example.test/avatar/'.$userId.'.png';
    }
}
