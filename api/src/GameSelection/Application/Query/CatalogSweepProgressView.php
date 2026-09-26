<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

/**
 * Where the rolling catalogue test stands on the image in use (story 38.9).
 */
final readonly class CatalogSweepProgressView
{
    public function __construct(
        public string $currentImage,
        public int $testedOnCurrentImage,
        public int $total,
    ) {
    }
}
