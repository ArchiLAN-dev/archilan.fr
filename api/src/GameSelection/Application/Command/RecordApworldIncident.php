<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Enum\ApworldIncidentStatus;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Repository\ApworldIncidentRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The one place that turns "this apworld has a problem" into incident state (story 38.1): open an
 * incident, or count a recurrence on the active one, unless an admin ignored this key for this
 * apworld hash. Every source of problems goes through here - the verdict reconciliation now, the
 * real generations (38.4) and the rejected updates (38.6) later - so the deduplication rule exists
 * once.
 *
 * Joins the caller's unit of work: it saves but never flushes. The caller flushes once, then
 * dispatches the alerts for what was opened.
 */
final readonly class RecordApworldIncident
{
    public function __construct(
        private ApworldIncidentRepositoryInterface $incidents,
        private ClockInterface $clock,
    ) {
    }

    public function record(string $gameId, string $apworldHash, ApworldIncidentType $type, string $error): ApworldIncidentRecording
    {
        $now = $this->clock->now();

        $active = $this->incidents->findActive($gameId, $apworldHash, $type);
        if (null !== $active) {
            $active->recordRecurrence($error, $now);

            return new ApworldIncidentRecording(ApworldIncidentRecordOutcome::Recurred, $active->getId());
        }

        // Ignoring holds for this apworld hash: a new hash is a new key, so a fixed upload that
        // breaks again is still seen. A resolved incident, on the other hand, relapses normally.
        $latestClosed = $this->incidents->findLatestClosed($gameId, $apworldHash, $type);
        if (null !== $latestClosed && ApworldIncidentStatus::Ignored === $latestClosed->getStatus()) {
            return new ApworldIncidentRecording(ApworldIncidentRecordOutcome::Suppressed, null);
        }

        $incident = ApworldIncident::open(bin2hex(random_bytes(16)), $gameId, $apworldHash, $type, $error, $now);
        $this->incidents->save($incident);

        return new ApworldIncidentRecording(ApworldIncidentRecordOutcome::Opened, $incident->getId());
    }
}
