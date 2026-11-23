<?php

declare(strict_types=1);

namespace App\Sessions\Application\Message;

/**
 * Story 56.3: dispatched once a dry generation's output is recorded, to measure it on the worker (a one-shot
 * container reads the multidata: never in the orchestrator's webhook request).
 */
final readonly class MeasureBalanceReportJob
{
    public function __construct(public string $reportId)
    {
    }
}
