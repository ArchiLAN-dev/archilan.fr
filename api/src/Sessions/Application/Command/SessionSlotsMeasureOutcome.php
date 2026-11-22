<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

/**
 * Result of measuring the slots of a generated session (story 56.1).
 */
enum SessionSlotsMeasureOutcome: string
{
    case Measured = 'measured';
    case UnknownSession = 'unknown_session';
    /** The session has no generated output yet, or its archive holds no multidata. */
    case NoOutput = 'no_output';
    /** The container could not read the multidata, or the image reports no measures. */
    case Unreadable = 'unreadable';
}
