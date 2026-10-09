<?php

declare(strict_types=1);

namespace App\Sessions\Application\Message;

/**
 * Dispatched when a slot of a private run leaves a real BK (story 40.1), to tell its players.
 */
final readonly class NotifySlotUnblockedJob
{
    public const string NOTIFICATION_TYPE = 'slot_unblocked';

    public function __construct(
        public string $sessionId,
        public string $slotName,
        public int $reachableNow,
        /** Story 40.5: the Archipelago slot number, for the slot's progression page; null on a job queued before. */
        public ?string $slotIndex = null,
    ) {
    }
}
