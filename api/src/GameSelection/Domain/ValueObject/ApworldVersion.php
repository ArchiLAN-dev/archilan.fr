<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\ValueObject;

/**
 * An apworld version read out of a GitHub tag or release name (story 38.5).
 *
 * Apworld authors tag however they like: `v0.18.2`, `0.18.2`, `CrystalProject-v0.18.2`,
 * `Crystal Project Version 0.18.2`. The old rule compared tags as strings after `ltrim('vV')`, so any
 * difference counted as an update - including an older release - and `CrystalProject-v...` kept its
 * prefix. Deciding an automatic update on that is not possible; this reads the number and orders it.
 *
 * The last dotted number in the tag is the version (a tag may mention the Archipelago version it
 * targets before its own), with as many components as the author wrote: `0.5.1.3` is newer than
 * `0.5.1.2`. A suffix after a hyphen is a pre-release only when it names one (`alpha`, `beta`, `rc`,
 * `pre`, `dev`, `preview`), and sorts below its final version; any other suffix (`-fix`, `-1`) is a fix
 * published under the same number, and sorts above it. GitHub already filters its flagged pre-releases.
 * A tag without any version number does not parse: the caller must treat that as "undetermined", never
 * as an update.
 */
final readonly class ApworldVersion
{
    private const string PATTERN = '/(\d+(?:\.\d+)+)(?:-([0-9A-Za-z][0-9A-Za-z.-]*))?/';
    private const string PRE_RELEASE_WORDS = '/^(alpha|beta|rc|pre|dev|preview)(?![a-z])/i';

    /**
     * @param list<int> $numbers at least major and minor, trailing zeros dropped
     */
    private function __construct(
        public array $numbers,
        public ?string $preRelease,
        public ?string $fix,
    ) {
    }

    public static function parse(string $tag): ?self
    {
        preg_match_all(self::PATTERN, $tag, $matches, \PREG_SET_ORDER);
        $last = end($matches);
        if (false === $last) {
            return null;
        }

        $numbers = array_map(intval(...), explode('.', $last[1]));
        // 1.4 and 1.4.0 are one version.
        while (\count($numbers) > 2 && 0 === end($numbers)) {
            array_pop($numbers);
        }

        $suffix = $last[2] ?? '';
        if ('' === $suffix) {
            return new self($numbers, null, null);
        }

        return 1 === preg_match(self::PRE_RELEASE_WORDS, $suffix)
            ? new self($numbers, $suffix, null)
            : new self($numbers, null, $suffix);
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
        $length = max(\count($this->numbers), \count($other->numbers));
        $byNumbers = array_pad($this->numbers, $length, 0) <=> array_pad($other->numbers, $length, 0);
        if (0 !== $byNumbers) {
            return $byNumbers;
        }

        // Same numbers: pre-releases below the final version, fixes above it.
        $rank = $this->rank() <=> $other->rank();
        if (0 !== $rank) {
            return $rank;
        }

        return strnatcasecmp($this->preRelease ?? $this->fix ?? '', $other->preRelease ?? $other->fix ?? '') <=> 0;
    }

    public function toString(): string
    {
        $version = implode('.', array_pad($this->numbers, 3, 0));

        return match (true) {
            null !== $this->preRelease => $version.'-'.$this->preRelease,
            null !== $this->fix => $version.'-'.$this->fix,
            default => $version,
        };
    }

    private function rank(): int
    {
        return match (true) {
            null !== $this->preRelease => -1,
            null !== $this->fix => 1,
            default => 0,
        };
    }
}
