<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Message;

/**
 * Scheduled marker (every 5 minutes): derive the apworld incidents from the orchestrator's test
 * verdicts (story 38.1).
 */
final readonly class ReconcileApworldIncidentsMessage
{
}
