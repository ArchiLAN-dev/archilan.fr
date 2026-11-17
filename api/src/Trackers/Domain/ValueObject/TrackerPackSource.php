<?php

declare(strict_types=1);

namespace App\Trackers\Domain\ValueObject;

use App\Trackers\Domain\Enum\TrackerPackSourceKind;

/**
 * A GitHub link an admin pasted to import a PopTracker pack (story 54.1). Only github.com links are read:
 * a repository (its latest release), a release page, a tag's source archive or a release asset.
 */
final readonly class TrackerPackSource
{
    private const string NAME_PATTERN = '/^[A-Za-z0-9_.-]{1,100}$/';

    private function __construct(
        public TrackerPackSourceKind $kind,
        public string $owner,
        public string $repo,
        public ?string $tag,
        public ?string $zipUrl,
    ) {
    }

    /** @throws \DomainException tracker_pack_url_invalid */
    public static function parse(string $url): self
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (false === $parts
            || 'https' !== ($parts['scheme'] ?? null)
            || 'github.com' !== strtolower($parts['host'] ?? '')
            || isset($parts['user'])
            || isset($parts['port'])) {
            throw new \DomainException('tracker_pack_url_invalid');
        }

        $segments = array_map(rawurldecode(...), explode('/', trim($parts['path'] ?? '', '/')));
        if (count($segments) < 2) {
            throw new \DomainException('tracker_pack_url_invalid');
        }
        $owner = $segments[0];
        $repo = preg_replace('/\.git$/', '', $segments[1]) ?? $segments[1];
        if (1 !== preg_match(self::NAME_PATTERN, $owner) || 1 !== preg_match(self::NAME_PATTERN, $repo)) {
            throw new \DomainException('tracker_pack_url_invalid');
        }
        $rest = array_slice($segments, 2);

        return match (true) {
            [] === $rest,
            ['releases'] === $rest,
            ['releases', 'latest'] === $rest => new self(TrackerPackSourceKind::LatestRelease, $owner, $repo, null, null),
            count($rest) >= 3 && 'releases' === $rest[0] && 'tag' === $rest[1] => new self(
                TrackerPackSourceKind::Release,
                $owner,
                $repo,
                self::validTag(implode('/', array_slice($rest, 2))),
                null,
            ),
            count($rest) >= 4 && 'archive' === $rest[0] && 'refs' === $rest[1] && 'tags' === $rest[2] => self::tagArchive($owner, $repo, implode('/', array_slice($rest, 3))),
            count($rest) >= 4 && 'releases' === $rest[0] && 'download' === $rest[1] => self::releaseAsset($owner, $repo, $rest),
            default => throw new \DomainException('tracker_pack_url_invalid'),
        };
    }

    /** The repository page, kept as the pack's source. */
    public function repositoryUrl(): string
    {
        return sprintf('https://github.com/%s/%s', $this->owner, $this->repo);
    }

    /** The source archive GitHub builds for a tag: its content sits under a `{repo}-{version}/` folder. */
    public static function tagArchiveUrl(string $owner, string $repo, string $tag): string
    {
        return sprintf('https://github.com/%s/%s/archive/refs/tags/%s.zip', $owner, $repo, implode('/', array_map(rawurlencode(...), explode('/', $tag))));
    }

    private static function tagArchive(string $owner, string $repo, string $file): self
    {
        if (!str_ends_with(strtolower($file), '.zip')) {
            throw new \DomainException('tracker_pack_url_invalid');
        }
        $tag = self::validTag(substr($file, 0, -4));

        return new self(TrackerPackSourceKind::Zip, $owner, $repo, $tag, self::tagArchiveUrl($owner, $repo, $tag));
    }

    /** @param list<string> $rest the path after the repository: releases/download/{tag}/{file}.zip */
    private static function releaseAsset(string $owner, string $repo, array $rest): self
    {
        $file = $rest[count($rest) - 1];
        if (!str_ends_with(strtolower($file), '.zip') || 1 !== preg_match('/^[^\/\\\\\x00]{1,200}$/', $file)) {
            throw new \DomainException('tracker_pack_url_invalid');
        }
        $tag = self::validTag(implode('/', array_slice($rest, 2, -1)));
        $url = sprintf(
            'https://github.com/%s/%s/releases/download/%s/%s',
            $owner,
            $repo,
            implode('/', array_map(rawurlencode(...), explode('/', $tag))),
            rawurlencode($file),
        );

        return new self(TrackerPackSourceKind::Zip, $owner, $repo, $tag, $url);
    }

    private static function validTag(string $tag): string
    {
        if ('' === $tag || strlen($tag) > 120 || str_contains($tag, '..') || 1 === preg_match('/[\x00-\x20\\\\?#]/', $tag)) {
            throw new \DomainException('tracker_pack_url_invalid');
        }

        return $tag;
    }
}
