<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * Result of {@see RecordApworldIncident::record()}. `incidentId` is the opened or recurred incident,
 * null when the report was suppressed.
 */
final readonly class ApworldIncidentRecording
{
    public function __construct(
        public ApworldIncidentRecordOutcome $outcome,
        public ?string $incidentId,
    ) {
    }
}
