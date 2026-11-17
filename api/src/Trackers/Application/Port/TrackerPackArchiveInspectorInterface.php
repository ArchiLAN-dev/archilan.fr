<?php

declare(strict_types=1);

namespace App\Trackers\Application\Port;

use App\Shared\Application\Exception\ValidationException;
use App\Trackers\Application\Support\InspectedTrackerPackArchive;

/** Checks a downloaded pack zip without unpacking it, and reads its manifest (story 54.1). */
interface TrackerPackArchiveInspectorInterface
{
    /**
     * @throws ValidationException tracker_pack_archive_invalid, tracker_pack_too_large, tracker_pack_unsafe_path,
     *                             tracker_pack_manifest_missing
     */
    public function inspect(string $archive): InspectedTrackerPackArchive;
}
