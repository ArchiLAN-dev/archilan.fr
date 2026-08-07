<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

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

    /**
     * Display names for a set of ids, whoever they are (story 36.2).
     *
     * Deliberately NOT {@see cards()}: that one only returns *listable* members - a public slug, not
     * banned, not suspended - because it feeds public surfaces. Naming the admin who applied a sanction
     * is the opposite need: the actor may well have no public profile, and a moderated account still has
     * to be named in an audit trail. Only deleted accounts drop out.
     *
     * @param list<string> $userIds
     *
     * @return array<string, string> keyed by userId
     */
    public function namesFor(array $userIds): array;
}
