<?php

declare(strict_types=1);

namespace App\Community\Application\Command;

/**
 * Outcome of {@see ContactModeration::write} (story 39.2).
 */
enum ContactModerationOutcome: string
{
    case Sent = 'sent';
    case Invalid = 'invalid';
    case NotSanctioned = 'not_sanctioned';
    case TooMany = 'too_many';
}
