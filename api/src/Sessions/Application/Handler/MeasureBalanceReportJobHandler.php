<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Sessions\Application\Command\RecordBalanceDryGeneration;
use App\Sessions\Application\Message\MeasureBalanceReportJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Story 56.3: measures a dry generation once its output is recorded. */
#[AsMessageHandler]
final readonly class MeasureBalanceReportJobHandler
{
    public function __construct(private RecordBalanceDryGeneration $record)
    {
    }

    public function __invoke(MeasureBalanceReportJob $job): void
    {
        $this->record->measure($job->reportId);
    }
}
