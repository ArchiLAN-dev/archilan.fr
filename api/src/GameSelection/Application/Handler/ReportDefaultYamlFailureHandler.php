<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Handler;

use App\GameSelection\Application\Command\ApworldIncidentRecordOutcome;
use App\GameSelection\Application\Command\ReportDefaultYamlFailure;
use App\GameSelection\Application\Message\ReportDefaultYamlFailureJob;
use App\GameSelection\Application\Support\ApworldIncidentAlertDispatcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Story 38.4: judges a real generation failure, then alerts on an incident it opened, exactly like an
 * incident of the import test (story 38.2). A recurrence alerts nobody.
 */
#[AsMessageHandler]
final readonly class ReportDefaultYamlFailureHandler
{
    public function __construct(
        private ReportDefaultYamlFailure $report,
        private ApworldIncidentAlertDispatcher $alerts,
    ) {
    }

    public function __invoke(ReportDefaultYamlFailureJob $job): void
    {
        $recording = $this->report->report($job->gameId, $job->apworldHash, $job->playerYaml, $job->error)->recording;

        if (null !== $recording && ApworldIncidentRecordOutcome::Opened === $recording->outcome && null !== $recording->incidentId) {
            $this->alerts->dispatchOpened($recording->incidentId);
        }
    }
}
