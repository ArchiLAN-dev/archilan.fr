<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/** Story 56.7: why a size search did or did not start. */
enum SizeSearchOutcome
{
    case Started;
    case InvalidYaml;
    case UnknownGame;
    case RequiresFile;
    /** No admin-designated size option, or none the YAML gives a usable value. */
    case NoSizeOptions;
    /** No event or run target to aim for. */
    case NoTarget;
    /** No pace for this game: a size cannot be turned into hours. */
    case UnknownPace;
}
