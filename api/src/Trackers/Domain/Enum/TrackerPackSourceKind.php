<?php

declare(strict_types=1);

namespace App\Trackers\Domain\Enum;

/** What a pasted GitHub link points at (story 54.1). */
enum TrackerPackSourceKind: string
{
    /** The repository itself: its latest published release. */
    case LatestRelease = 'latest_release';

    /** One release, by its tag. */
    case Release = 'release';

    /** A zip file: a release asset or a tag's source archive. */
    case Zip = 'zip';
}
