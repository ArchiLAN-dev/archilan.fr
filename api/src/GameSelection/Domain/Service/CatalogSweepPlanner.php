<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Service;

use App\GameSelection\Domain\ValueObject\SweepCandidate;

/**
 * Picks the apworlds the nightly sweep retests (story 38.9): a small batch every night rather than the
 * whole catalogue on every new image, so a new image is validated over a few nights without any peak.
 *
 * Order: verdicts obtained on another image first - an unknown image counts as another one
 * ({@see ArchipelagoImageFreshness}) - then apworlds never tested, then the oldest verdicts on the
 * current image. Oldest first within a rank. An apworld served by two games is tested once.
 *
 * Pure: no database, no clock.
 */
final class CatalogSweepPlanner
{
    private const int RANK_OTHER_IMAGE = 0;
    private const int RANK_NEVER_TESTED = 1;
    private const int RANK_CURRENT_IMAGE = 2;

    /**
     * @param list<SweepCandidate> $candidates
     *
     * @return list<string> the apworld hashes to retest, at most $batchSize
     */
    public static function plan(array $candidates, string $currentImage, ?string $currentImageId, int $batchSize): array
    {
        $ranked = [];
        foreach ($candidates as $candidate) {
            if ($candidate->excluded || isset($ranked[$candidate->apworldHash])) {
                continue;
            }
            $ranked[$candidate->apworldHash] = [self::rank($candidate, $currentImage, $currentImageId), $candidate->checkedAt?->getTimestamp() ?? 0];
        }

        // Stable: equal rank and date keep the given order.
        uasort($ranked, static fn (array $a, array $b): int => $a <=> $b);

        return \array_slice(array_map(strval(...), array_keys($ranked)), 0, max(0, $batchSize));
    }

    private static function rank(SweepCandidate $candidate, string $currentImage, ?string $currentImageId): int
    {
        if (null === $candidate->checkedAt) {
            return self::RANK_NEVER_TESTED;
        }

        return true === ArchipelagoImageFreshness::isCurrent($candidate->verdictImage, $candidate->verdictImageId, $currentImage, $currentImageId)
            ? self::RANK_CURRENT_IMAGE
            : self::RANK_OTHER_IMAGE;
    }
}
