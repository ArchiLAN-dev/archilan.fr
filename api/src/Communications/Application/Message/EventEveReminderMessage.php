<?php

declare(strict_types=1);

namespace App\Communications\Application\Message;

/** Story 53.8: a confirmed registrant, the day before their event. */
final readonly class EventEveReminderMessage
{
    /**
     * @param list<string> $stepsLeft what the registrant still has to do, worded for them; empty when they are ready
     */
    public function __construct(
        public string $userEmail,
        public ?string $userDisplayName,
        public string $eventTitle,
        public string $startsAt,
        public string $venue,
        public array $stepsLeft,
        public string $hubUrl,
        /** Story 47.7: the registrant can send their screen to the régie (media server, broadcast not refused). */
        public bool $localBroadcast = false,
    ) {
    }
}
