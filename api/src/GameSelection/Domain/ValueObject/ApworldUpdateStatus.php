<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\ValueObject;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Entity\GameCatalogSync;

/**
 * Pure version-comparison policy for an apworld's update status.
 *
 * Extracted so the read-side catalog query can compute the same status as the
 * {@see GameCatalogSync} aggregate without a hydrated entity. Keep the two in lockstep.
 */
final readonly class ApworldUpdateStatus
{
    public static function compute(
        ?string $sourceUrl,
        ?\DateTimeImmutable $checkedAt,
        ?string $latestVersion,
        ?string $deployedVersion,
    ): string {
        if (null === $sourceUrl || '' === $sourceUrl) {
            return Game::UPDATE_STATUS_NOT_TRACKED;
        }

        if (!str_starts_with($sourceUrl, 'https://github.com/')) {
            return Game::UPDATE_STATUS_NOT_TRACKED;
        }

        if (null === $checkedAt || null === $latestVersion) {
            return Game::UPDATE_STATUS_UNKNOWN;
        }

        if (null === $deployedVersion) {
            return Game::UPDATE_STATUS_UNKNOWN;
        }

        // Story 38.5: ordered like semver, so an older release is never an update. Anything that does not
        // read as a version number is "undetermined": shown to admins, never used to update.
        $latest = ApworldVersion::parse($latestVersion);
        $deployed = ApworldVersion::parse($deployedVersion);
        if (null === $latest || null === $deployed) {
            return Game::UPDATE_STATUS_UNDETERMINED;
        }

        return $latest->isNewerThan($deployed)
            ? Game::UPDATE_STATUS_UPDATE_AVAILABLE
            : Game::UPDATE_STATUS_UP_TO_DATE;
    }
}
