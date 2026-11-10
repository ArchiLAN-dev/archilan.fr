<?php

declare(strict_types=1);

namespace App\Registrations\Application\Query;

/**
 * Story 52.2: who is at the event when, and whether a member is away right now (the Passeur, epic 52).
 *
 * @phpstan-type PresenceTimelineRow array{registrationId: string, userId: string, displayName: string, windows: list<array{startsAt: string, endsAt: string}>}
 * @phpstan-type PresenceTimeline array{eventStartsAt: string, eventEndsAt: string, registrants: list<PresenceTimelineRow>}
 */
interface PresenceTimelineQueryInterface
{
    /**
     * The confirmed registrants of the event (reserved, submitted), by name, each with their windows (empty: there
     * the whole event).
     *
     * @return PresenceTimeline|null null when the event does not exist
     */
    public function timeline(string $eventId): ?array;

    /** True only when the registration has at least one window and none of them holds `$at`. */
    public function isOutsideWindows(string $registrationId, \DateTimeImmutable $at): bool;

    /** Story 52.6: when the member said they leave for good - the end of their last window; null without windows. */
    public function declaredDepartureAt(string $registrationId): ?\DateTimeImmutable;
}
