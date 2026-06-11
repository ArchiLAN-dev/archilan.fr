<?php

declare(strict_types=1);

namespace App\Sessions\Application;

/**
 * Reads the generated output archive of a session (`{sessionId}/output/archive.zip` on
 * durable storage): lists the file names it contains and extracts a single file by name.
 *
 * This is the durable replacement for the live bridge `/output` endpoint - it works whatever
 * the run's runtime state. Callers are responsible for filtering which entries a given user
 * may see (e.g. own-slot patches only). Implemented in Infrastructure.
 */
interface SessionOutputArtifactReaderInterface
{
    /**
     * @param string $outputKey storage key of the output archive
     *
     * @return list<string> the base file names inside the archive (empty when missing/unreadable)
     */
    public function listEntries(string $outputKey): array;

    /**
     * @param string $outputKey storage key of the output archive
     * @param string $entryName base file name to extract (matched on basename)
     *
     * @return SessionOutputArtifact|null null when the archive or the entry is missing
     */
    public function extractEntry(string $outputKey, string $entryName): ?SessionOutputArtifact;
}
