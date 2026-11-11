<?php

declare(strict_types=1);

namespace App\Events\Domain\Service;

use App\Events\Domain\Entity\Event;

/**
 * Story 53.2: where an event stands for its registrants. Mirrors `eventPhase` in the frontend (story 53.1): the
 * admin's status wins (an event started early is `during`, one closed early is `after`), otherwise the dates decide.
 */
final class EventPhase
{
    public const string BEFORE = 'before';
    public const string DURING = 'during';
    public const string AFTER = 'after';

    public static function of(Event $event, \DateTimeImmutable $now): string
    {
        return self::at($event->getStatus(), $event->getStartsAt(), $event->getEndsAt(), $now);
    }

    public static function at(string $status, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, \DateTimeImmutable $now): string
    {
        if (Event::STATUS_COMPLETED === $status) {
            return self::AFTER;
        }
        if (Event::STATUS_IN_PROGRESS === $status) {
            return self::DURING;
        }
        if ($now > $endsAt) {
            return self::AFTER;
        }
        if ($now >= $startsAt) {
            return self::DURING;
        }

        return self::BEFORE;
    }
}
