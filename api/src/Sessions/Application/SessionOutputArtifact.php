<?php

declare(strict_types=1);

namespace App\Sessions\Application;

/**
 * A single file extracted from a session's generated output archive.
 */
final readonly class SessionOutputArtifact
{
    public function __construct(
        public string $filename,
        public string $contents,
    ) {
    }
}
