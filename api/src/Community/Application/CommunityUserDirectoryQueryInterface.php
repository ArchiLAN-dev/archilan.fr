<?php

declare(strict_types=1);

namespace App\Community\Application;

interface CommunityUserDirectoryQueryInterface
{
    public function userIdForSlug(string $slug): ?string;

    /**
     * Resolve user cards for a set of ids (identity + cached avatar), for friends/requests lists.
     *
     * @param list<string> $userIds
     *
     * @return array<string, array{userId: string, slug: string, displayName: string|null, avatarUrl: string|null}> keyed by userId
     */
    public function cards(array $userIds): array;
}
