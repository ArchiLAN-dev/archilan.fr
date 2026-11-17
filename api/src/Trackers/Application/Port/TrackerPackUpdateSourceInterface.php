<?php

declare(strict_types=1);

namespace App\Trackers\Application\Port;

use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Exception\ValidationException;
use App\Trackers\Domain\ValueObject\TrackerPackSource;
use App\Trackers\Domain\ValueObject\TrackerPackUpdate;

/** Where newer versions of a pack are announced (story 54.3). Only GitHub hosts are ever contacted. */
interface TrackerPackUpdateSourceInterface
{
    /**
     * The highest version the pack's `versions.json` announces, or null when it lists none.
     *
     * @throws ValidationException         tracker_pack_versions_file_invalid, tracker_pack_url_invalid
     * @throws ServiceUnavailableException github_rate_limited, tracker_pack_download_failed
     */
    public function fromVersionsFile(string $versionsUrl): ?TrackerPackUpdate;

    /**
     * The latest release of the pack's repository, or null when it has none.
     *
     * @throws ServiceUnavailableException github_rate_limited, tracker_pack_download_failed
     */
    public function fromLatestRelease(TrackerPackSource $source): ?TrackerPackUpdate;
}
