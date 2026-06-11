<?php

declare(strict_types=1);

namespace App\Sessions\Application;

/**
 * A single spoiler log extracted from a session's generated output archive.
 */
final readonly class SpoilerArtifact
{
    public function __construct(
        public string $filename,
        public string $contents,
    ) {
    }
}
