<?php

declare(strict_types=1);

namespace App\Notes\Domain\Enum;

/** An open note still needs attention; a settled one is put away (story 46.1). */
enum NoteStatus: string
{
    case Open = 'open';
    case Settled = 'settled';
}
