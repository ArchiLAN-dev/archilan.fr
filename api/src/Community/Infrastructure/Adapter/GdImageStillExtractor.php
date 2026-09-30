<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Adapter;

use App\Community\Application\Port\ImageStillExtractor;

/**
 * GD reads the first image of a GIF only, which is the frame wanted (story 30.40); it is written back as a PNG,
 * keeping the GIF's transparency.
 */
final readonly class GdImageStillExtractor implements ImageStillExtractor
{
    public function firstFrameAsPng(string $gif): ?string
    {
        // A malformed GIF makes GD warn before it fails: the failure is the answer, not the warning.
        $image = @imagecreatefromstring($gif);
        if (false === $image) {
            return null;
        }

        imagesavealpha($image, true);
        ob_start();
        $written = imagepng($image);
        $png = ob_get_clean();

        return $written && is_string($png) && '' !== $png ? $png : null;
    }
}
