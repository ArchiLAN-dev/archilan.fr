<?php

declare(strict_types=1);

namespace App\Sessions\Application\Message;

/**
 * Story 56.1: dispatched once a session's generated output is recorded, to measure its slots on the worker
 * (a one-shot container reads the multidata: never in the orchestrator's webhook request).
 */
final readonly class MeasureSessionSlotsJob
{
    public function __construct(public string $sessionId)
    {
    }
}
