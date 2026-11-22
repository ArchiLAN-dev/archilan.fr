<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Sessions\Application\Command\MeasureSessionSlots;
use App\Sessions\Application\Message\MeasureSessionSlotsJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Story 56.1: measures the slots of a session that has just been generated. An unreadable archive is not an error
 * to retry: the command logs it and the slots stay unmeasured, which `app:sessions:measure-slots` can repair.
 */
#[AsMessageHandler]
final readonly class MeasureSessionSlotsJobHandler
{
    public function __construct(private MeasureSessionSlots $measureSessionSlots)
    {
    }

    public function __invoke(MeasureSessionSlotsJob $job): void
    {
        $this->measureSessionSlots->execute($job->sessionId);
    }
}
