<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

/**
 * Story 11.11: the result of starting the solo test of an event slot's config.
 */
enum EventSlotYamlTestOutcome: string
{
    case Started = 'started';
    /** No such registration in this event, no such slot, or a game without a ready apworld. */
    case NotFound = 'not_found';
    /** Neither a saved YAML nor a default one for the game. */
    case NoYaml = 'no_yaml';
    /** The game reads a file at generation (a ROM) and none was joined (story 44.7). */
    case RequiresFile = 'requires_file';
    /** The orchestrator did not take the joined file. */
    case FileRefused = 'file_refused';
    case RunnerUnavailable = 'runner_unavailable';
}
