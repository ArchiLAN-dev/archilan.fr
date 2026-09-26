<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Message;

/**
 * Download one apworld release found by the nightly check and submit it as a candidate (story 38.6).
 * One job per game: an upload takes tens of seconds, and one failing download must not stop the others.
 */
final readonly class SubmitAutoApworldUpdateJob
{
    public function __construct(
        public string $gameId,
        public string $versionTag,
        public string $assetDownloadUrl,
        public string $assetName,
    ) {
    }
}
