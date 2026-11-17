<?php

declare(strict_types=1);

namespace App\Trackers\Domain\ValueObject;

/**
 * A newer version of a pack, found by the update check (story 54.3): from the pack's `versions.json` or from the
 * latest GitHub release of its repository. Stored on the pack until an admin applies it.
 */
final readonly class TrackerPackUpdate
{
    public const int MAX_CHANGELOG_CHARS = 5000;

    /** @param list<string> $changelog */
    public function __construct(
        public string $packageVersion,
        public string $downloadUrl,
        public ?string $sha256,
        public array $changelog,
        public ?string $gitTag,
    ) {
    }

    /**
     * Release notes as lines, at most {@see MAX_CHANGELOG_CHARS} characters in all, blank lines dropped.
     *
     * @return list<string>
     */
    public static function changelogFromText(string $text): array
    {
        $lines = [];
        $left = self::MAX_CHANGELOG_CHARS;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = rtrim($line);
            if ('' === trim($line) || $left <= 0) {
                continue;
            }
            $line = mb_substr($line, 0, $left);
            $left -= mb_strlen($line);
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public static function changelogFromLines(array $lines): array
    {
        return self::changelogFromText(implode("\n", $lines));
    }

    /** @return array{packageVersion: string, downloadUrl: string, sha256: ?string, changelog: list<string>, gitTag: ?string} */
    public function toArray(): array
    {
        return [
            'packageVersion' => $this->packageVersion,
            'downloadUrl' => $this->downloadUrl,
            'sha256' => $this->sha256,
            'changelog' => $this->changelog,
            'gitTag' => $this->gitTag,
        ];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $version = $data['packageVersion'] ?? null;
        $url = $data['downloadUrl'] ?? null;
        if (!is_string($version) || !is_string($url)) {
            return null;
        }
        $sha = $data['sha256'] ?? null;
        $tag = $data['gitTag'] ?? null;
        $changelog = is_array($data['changelog'] ?? null) ? array_values(array_filter($data['changelog'], is_string(...))) : [];

        return new self($version, $url, is_string($sha) ? $sha : null, $changelog, is_string($tag) ? $tag : null);
    }
}
