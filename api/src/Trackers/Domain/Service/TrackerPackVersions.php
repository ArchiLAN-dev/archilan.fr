<?php

declare(strict_types=1);

namespace App\Trackers\Domain\Service;

use App\Trackers\Domain\Entity\TrackerPackVersion;
use App\Trackers\Domain\ValueObject\TrackerPackUpdate;

/**
 * Version arithmetic of packs (story 54.3): pack authors write `v0.9.5.3` in tags and `0.9.5.3` in manifests, with
 * three or four segments.
 */
final readonly class TrackerPackVersions
{
    /** Versions kept per pack, the one served included. */
    public const int KEPT = 3;

    public static function compare(string $a, string $b): int
    {
        return version_compare(self::normalize($a), self::normalize($b));
    }

    /**
     * The update to offer: the highest of what the pack's `versions.json` and its latest release announce, only when
     * above the version served. A `versions.json` lagging behind the releases (Majora's Mask) does not hide them.
     */
    public static function pickUpdate(?string $current, ?TrackerPackUpdate ...$candidates): ?TrackerPackUpdate
    {
        $best = null;
        foreach ($candidates as $candidate) {
            if (null !== $candidate && (null === $best || self::compare($candidate->packageVersion, $best->packageVersion) > 0)) {
                $best = $candidate;
            }
        }
        if (null === $best || (null !== $current && self::compare($best->packageVersion, $current) <= 0)) {
            return null;
        }

        return $best;
    }

    /**
     * The highest version a pack's `versions.json` announces:
     * `{"versions": [{"package_version", "download_url", "sha256", "changelog": [...]}]}`. Entries without a
     * version or an https link are skipped.
     *
     * @param array<mixed> $file the decoded file
     */
    public static function fromVersionsFile(array $file): ?TrackerPackUpdate
    {
        $best = null;
        foreach (is_array($file['versions'] ?? null) ? $file['versions'] : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $version = $entry['package_version'] ?? null;
            $url = $entry['download_url'] ?? null;
            if (!is_string($version) || '' === trim($version) || !is_string($url) || !str_starts_with($url, 'https://')) {
                continue;
            }
            if (null !== $best && self::compare($version, $best->packageVersion) <= 0) {
                continue;
            }
            $sha = $entry['sha256'] ?? null;
            $changelog = $entry['changelog'] ?? null;
            $best = new TrackerPackUpdate(
                mb_substr(trim($version), 0, 64),
                $url,
                is_string($sha) && 1 === preg_match('/^[0-9a-f]{64}$/i', $sha) ? strtolower($sha) : null,
                match (true) {
                    is_array($changelog) => TrackerPackUpdate::changelogFromLines(array_values(array_filter($changelog, is_string(...)))),
                    is_string($changelog) => TrackerPackUpdate::changelogFromText($changelog),
                    default => [],
                },
                null,
            );
        }

        return $best;
    }

    /** The version a release tag names: `v0.9.5.3` gives `0.9.5.3`. */
    public static function fromTag(string $tag): string
    {
        return mb_substr(self::normalize($tag), 0, 64);
    }

    /**
     * The versions to let go once `$currentId` is served: all but the current one and the most recent others, up to
     * {@see KEPT} in all.
     *
     * @param list<TrackerPackVersion> $versions every version of the pack
     *
     * @return list<TrackerPackVersion>
     */
    public static function expired(array $versions, string $currentId): array
    {
        $others = array_values(array_filter($versions, static fn (TrackerPackVersion $v): bool => $v->getId() !== $currentId));
        usort($others, static fn (TrackerPackVersion $a, TrackerPackVersion $b): int => [$b->getImportedAt(), $b->getId()] <=> [$a->getImportedAt(), $a->getId()]);

        return array_slice($others, self::KEPT - 1);
    }

    private static function normalize(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }
}
