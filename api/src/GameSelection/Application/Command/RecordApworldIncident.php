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

    /**
     * @param string|null $observation what identifies this observation when the source has one (the
     *                                 verdict's checkedAt): the same observation is never counted twice,
     *                                 nor reopens an incident closed on it. Null: every report counts.
     */
    public function record(string $gameId, string $apworldHash, ApworldIncidentType $type, string $error, ?string $observation = null): ApworldIncidentRecording
    {
        $now = $this->clock->now();

        $active = $this->incidents->findActive($gameId, $apworldHash, $type);
        if (null !== $active) {
            return new ApworldIncidentRecording(
                $active->recordRecurrence($error, $now, $observation) ? ApworldIncidentRecordOutcome::Recurred : ApworldIncidentRecordOutcome::AlreadySeen,
                $active->getId(),
            );
        }

        // Ignoring holds for this apworld hash: a new hash is a new key, so a fixed upload that
        // breaks again is still seen. A resolved incident relapses, but only on a new observation:
        // closed by hand on this very verdict, it stays closed until the apworld is tested again.
        $latestClosed = $this->incidents->findLatestClosed($gameId, $apworldHash, $type);
        if (null !== $latestClosed && ApworldIncidentStatus::Ignored === $latestClosed->getStatus()) {
            return new ApworldIncidentRecording(ApworldIncidentRecordOutcome::Suppressed, null);
        }
        if (null !== $latestClosed && $latestClosed->isObservation($observation)) {
            return new ApworldIncidentRecording(ApworldIncidentRecordOutcome::AlreadySeen, null);
        }

        $incident = ApworldIncident::open(bin2hex(random_bytes(16)), $gameId, $apworldHash, $type, $error, $now, $observation);
        $this->incidents->save($incident);

        return new ApworldIncidentRecording(ApworldIncidentRecordOutcome::Opened, $incident->getId());
    }
}
