<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\ValueObject;

/**
 * An apworld version read out of a GitHub tag or release name (story 38.5).
 *
 * Apworld authors tag however they like: `v0.18.2`, `0.18.2`, `CrystalProject-v0.18.2`,
 * `Crystal Project Version 0.18.2`. The old rule compared tags as strings after `ltrim('vV')`, so any
 * difference counted as an update - including an older release - and `CrystalProject-v...` kept its
 * prefix. Deciding an automatic update on that is not possible; this reads the number and orders it
 * like semver.
 *
 * The last `X.Y` or `X.Y.Z` in the tag is the version (a tag may mention the Archipelago version it
 * targets before its own). A pre-release sorts below its final version. A tag without any version
 * number does not parse: the caller must treat that as "undetermined", never as an update.
 */
final readonly class ApworldVersion
{
    private const string PATTERN = '/(\d+)\.(\d+)(?:\.(\d+))?(?:-([0-9A-Za-z][0-9A-Za-z.-]*))?/';

    private function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        public ?string $preRelease,
    ) {
    }

    public static function parse(string $tag): ?self
    {
        preg_match_all(self::PATTERN, $tag, $matches, \PREG_SET_ORDER);
        $last = end($matches);
        if (false === $last) {
            return null;
        }
        $preRelease = $last[4] ?? '';

        return new self(
            (int) $last[1],
            (int) $last[2],
            (int) ($last[3] ?? 0),
            '' === $preRelease ? null : $preRelease,
        );
    }

    public function isNewerThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function equals(self $other): bool
    {
        return 0 === $this->compareTo($other);
    }

    public function compareTo(self $other): int
    {
        $byNumbers = [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
        if (0 !== $byNumbers) {
            return $byNumbers;
        }

        // Same numbers: the final version ranks above any of its pre-releases.
        return match (true) {
            null === $this->preRelease && null === $other->preRelease => 0,
            null === $this->preRelease => 1,
            null === $other->preRelease => -1,
            default => strnatcmp($this->preRelease, $other->preRelease) <=> 0,
        };
    }

    public function toString(): string
    {
        $version = sprintf('%d.%d.%d', $this->major, $this->minor, $this->patch);

        return null === $this->preRelease ? $version : $version.'-'.$this->preRelease;
    }
}
