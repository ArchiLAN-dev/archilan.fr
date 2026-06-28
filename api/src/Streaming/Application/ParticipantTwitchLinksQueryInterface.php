<?php

declare(strict_types=1);

namespace App\Streaming\Application;

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
}
