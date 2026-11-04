<?php

declare(strict_types=1);

namespace App\Community\Domain\ValueObject;

use App\Community\Domain\Enum\ImageFormat;

/** What an uploaded file's bytes say it is (story 30.40). */
final readonly class InspectedImage
{
    public function __construct(
        public ImageFormat $format,
        public bool $animated,
        // Story 50.3: the largest surface the file declares, what a decoder has to allocate. Read for GIFs only.
        public int $pixels = 0,
    ) {
    }
}
