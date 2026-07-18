<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * Result of {@see UploadTutorialImageCommand::execute}: the storage {@see self::$key} persisted on the
 * tutorial step, plus a presigned {@see self::$url} for immediate preview (re-derived at read time).
 */
final readonly class TutorialImageUpload
{
    public function __construct(
        public string $key,
        public string $url,
    ) {
    }
}
