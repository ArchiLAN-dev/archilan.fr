<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * What {@see ReportDefaultYamlFailure} made of a generation failure (story 38.4): `recording` is set
 * only when the apworld was accused.
 */
final readonly class DefaultYamlFailureReport
{
    public function __construct(
        public DefaultYamlFailureVerdict $verdict,
        public ?ApworldIncidentRecording $recording = null,
    ) {
    }
}
