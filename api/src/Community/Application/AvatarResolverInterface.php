<?php

declare(strict_types=1);

namespace App\Community\Application;

/**
 * Resolves a member's avatar URL from external sources (Discord, Steam…), applying source precedence.
 * Returns null when no source is linked / resolvable. Implementations are Infrastructure adapters and
 * MUST be best-effort: never throw, never block - a failed external call resolves to null (story 30.2).
 */
interface AvatarResolverInterface
{
    public function resolve(string $userId): ?string;
}
