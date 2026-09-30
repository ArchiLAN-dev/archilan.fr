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
    ) {
    }
}
