<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Service;

/**
 * Whether an apworld verdict was produced on the Archipelago image that runs now (story 38.8).
 *
 * The image id decides when both sides know it: it tells apart `archipelago:latest` rebuilt locally,
 * or a tag pushed again, where the reference alone stays the same. Without an id on either side, the
 * references decide. A verdict without image predates the story: it counts as tested on an older
 * image, so the rolling test (story 38.9) checks it first.
 *
 * Pure: works on the values the orchestrator reported.
 */
final class ArchipelagoImageFreshness
{
    public static function isCurrent(?string $verdictImage, ?string $verdictImageId, string $currentImage, ?string $currentImageId): bool
    {
        if (null === $verdictImage || '' === $verdictImage) {
            return false;
        }

        if (null !== $verdictImageId && '' !== $verdictImageId && null !== $currentImageId && '' !== $currentImageId) {
            return $verdictImageId === $currentImageId;
        }

        return $verdictImage === $currentImage;
    }
}
