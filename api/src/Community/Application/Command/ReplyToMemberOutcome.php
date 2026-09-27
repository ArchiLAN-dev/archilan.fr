<?php

declare(strict_types=1);

namespace App\Community\Application\Command;

/**
 * Outcome of {@see ReplyToMember::reply} (story 39.3).
 */
enum ReplyToMemberOutcome: string
{
    case Sent = 'sent';
    case Invalid = 'invalid';
    case NotSanctioned = 'not_sanctioned';
}
