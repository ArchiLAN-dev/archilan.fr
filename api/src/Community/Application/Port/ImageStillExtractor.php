<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

/**
 * The first frame of an animated image, as a still PNG (story 30.40): what a profile shows once its GIF may no
 * longer move.
 */
interface ImageStillExtractor
{
    /** Null when the image cannot be read. */
    public function firstFrameAsPng(string $gif): ?string;
}
