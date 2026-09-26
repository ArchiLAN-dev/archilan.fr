<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Enum;

/**
 * Who brought an apworld candidate (story 38.6): an admin (file or GitHub import) or the nightly update.
 */
enum ApworldCandidateOrigin: string
{
    case Manual = 'manual';
    case Auto = 'auto';
}
