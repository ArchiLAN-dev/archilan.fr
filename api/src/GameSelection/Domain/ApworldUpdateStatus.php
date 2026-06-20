<?php

declare(strict_types=1);

namespace App\GameSelection\Domain;

/**
 * Pure version-comparison policy for an apworld's update status.
 *
 * Extracted so the read-side catalog query can compute the same status as the
 * {@see GameCatalogSync} aggregate without a hydrated entity. Keep the two in lockstep.
 */
final class ApworldUpdateStatus
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

        $latest = ltrim($latestVersion, 'vV');
        $deployed = ltrim($deployedVersion, 'vV');

        return $latest === $deployed
            ? Game::UPDATE_STATUS_UP_TO_DATE
            : Game::UPDATE_STATUS_UPDATE_AVAILABLE;
    }
}
