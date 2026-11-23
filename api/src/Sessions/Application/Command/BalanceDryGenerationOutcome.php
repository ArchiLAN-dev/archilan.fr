<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

/** Story 56.3: what came of asking for a dry generation. */
enum BalanceDryGenerationOutcome
{
    case Started;
    case NotFound;
    case Forbidden;
    case NoSlots;
    /** A previous dry generation of the same scope is still running. */
    case AlreadyGenerating;
    /** A world reads a file at generation: the report says which, nothing was generated. */
    case NeedsFiles;
    /** An imported seed is not generated. */
    case ImportedSeed;
    /** The orchestrator refused the YAMLs or did not answer: the report carries the reason. */
    case Failed;
}
