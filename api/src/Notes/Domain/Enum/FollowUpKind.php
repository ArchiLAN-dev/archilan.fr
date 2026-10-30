<?php

declare(strict_types=1);

namespace App\Notes\Domain\Enum;

/** A follow-up tells how a note evolved: a comment, or the note being settled or reopened (story 46.1). */
enum FollowUpKind: string
{
    case Comment = 'comment';
    case Settled = 'settled';
    case Reopened = 'reopened';
}
