<?php

declare(strict_types=1);

namespace App\Streaming\Application\Query;

/**
 * Read side: participants of a session together with their community-profile social links.
 *
 * Each method returns null when the parent session does not exist (so the caller can answer 404),
 * or a list of participant rows (possibly empty when the session exists but has no eligible participants).
 * Banned/suspended/deleted users are excluded by the implementation.
 *
 * @phpstan-type ParticipantLinkRow array{userId: string, slug: string, displayName: string|null, socialLinks: list<array{label: string, url: string}>}
 */
interface ParticipantTwitchLinksQueryInterface
{
    /** @return list<ParticipantLinkRow>|null */
    public function forEvent(string $eventId): ?array;

    /** @return list<ParticipantLinkRow>|null */
    public function forPersonalRun(string $runId): ?array;

    /** @return list<ParticipantLinkRow>|null */
    public function forWeeklyRun(string $weeklyRunId): ?array;

    /**
     * Les liens sociaux d'un ensemble de membres, pour la page communauté (story 30.39).
     *
     * Contrairement aux trois autres, aucune session ne borne l'ensemble : les identifiants viennent
     * de l'appelant. Rend une liste vide plutôt que `null` - il n'y a pas ici de « ça n'existe pas »
     * à distinguer d'un « personne n'a de lien ».
     *
     * @param list<string> $userIds
     *
     * @return list<ParticipantLinkRow>
     */
    public function forUserIds(array $userIds): array;
}
