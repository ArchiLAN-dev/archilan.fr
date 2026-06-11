<?php

declare(strict_types=1);

namespace App\Sessions\Application;

/**
 * Reads the spoiler log out of a session's generated output archive
 * (`{sessionId}/output/archive.zip` on durable storage). Returns only the spoiler entry -
 * never the multidata (`.archipelago`) nor any player patch. Implemented in Infrastructure.
 */
interface SessionSpoilerArtifactReaderInterface
{
    /**
     * @param string $outputKey storage key of the output archive
     *
     * @return SpoilerArtifact|null null when the archive is missing/unreadable or contains no spoiler
     */
    public function extractSpoiler(string $outputKey): ?SpoilerArtifact;
}
