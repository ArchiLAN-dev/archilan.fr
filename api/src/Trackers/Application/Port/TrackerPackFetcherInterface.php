<?php

declare(strict_types=1);

namespace App\Trackers\Application\Port;

use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Exception\ValidationException;
use App\Trackers\Application\Support\FetchedTrackerPack;
use App\Trackers\Domain\ValueObject\TrackerPackSource;

/** Downloads a pack from GitHub (story 54.1). Only GitHub hosts are ever contacted. */
interface TrackerPackFetcherInterface
{
    /**
     * @throws ValidationException         tracker_pack_release_not_found, tracker_pack_no_zip, tracker_pack_too_large
     * @throws ServiceUnavailableException github_rate_limited, tracker_pack_download_failed
     */
    public function fetch(TrackerPackSource $source): FetchedTrackerPack;
}
