<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

/** A file of a session's archive, ready to download (story 17.37). */
final readonly class ArchivedFile
{
    public function __construct(
        public string $filename,
        public string $contents,
    ) {
    }
}
