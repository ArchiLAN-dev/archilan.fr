<?php

declare(strict_types=1);

namespace App\Streaming\Application\Support;

use App\Events\Domain\Entity\Event;
use App\Identity\Domain\Entity\User;
use App\Registrations\Domain\Entity\Registration;

/**
 * Story 47.5: whether a registrant may publish to their event's LAN media server right now, and if not, why. Read by
 * the media server's authorization (each publication) and by the registrant's own view (47.7 card).
 */
final class MediaPublishEligibility
{
    public const string NO_MEDIA_SERVER = 'no_media_server';
    public const string EVENT_CLOSED = 'event_closed';
    public const string OUTSIDE_WINDOW = 'outside_window';
    public const string NOT_CONFIRMED = 'not_confirmed';
    public const string BROADCAST_REFUSED = 'broadcast_refused';
    public const string ACCOUNT_UNAVAILABLE = 'account_unavailable';

    /** Publishing opens the day before, so the players can set OBS up ahead. */
    private const string OPENS_BEFORE_START = '-24 hours';

    public static function opensAt(Event $event): \DateTimeImmutable
    {
        return $event->getStartsAt()->modify(self::OPENS_BEFORE_START);
    }

    public static function closesAt(Event $event): \DateTimeImmutable
    {
        return $event->getEndsAt();
    }

    /** Null when the registrant may publish, otherwise the first reason they may not. */
    public static function refusal(Event $event, Registration $registration, ?User $user, \DateTimeImmutable $now): ?string
    {
        return match (true) {
            null === $event->getMediaServerHost() => self::NO_MEDIA_SERVER,
            !in_array($event->getStatus(), [Event::STATUS_PUBLISHED, Event::STATUS_IN_PROGRESS], true) => self::EVENT_CLOSED,
            $now < self::opensAt($event) || $now > self::closesAt($event) => self::OUTSIDE_WINDOW,
            !$registration->isReserved() || null === $registration->getSubmittedAt() => self::NOT_CONFIRMED,
            !$registration->hasBroadcastConsent() => self::BROADCAST_REFUSED,
            !$user instanceof User || null !== $user->getDeletedAt() || null !== $user->getBannedAt() => self::ACCOUNT_UNAVAILABLE,
            default => null,
        };
    }
}
