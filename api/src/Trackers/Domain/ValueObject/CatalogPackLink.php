<?php

declare(strict_types=1);

namespace App\Trackers\Domain\ValueObject;

/**
 * A PopTracker pack the community catalog names for a game (story 54.12). A GitHub repository is importable; any
 * other link (a Discord message, another host) is only shown, to fetch by hand.
 */
final readonly class CatalogPackLink
{
    private function __construct(
        public string $label,
        public string $url,
        public ?string $owner,
        public ?string $repo,
    ) {
    }

    /** Reads a catalog link; null when it does not name a PopTracker pack or has no address. */
    public static function fromCatalog(string $label, ?string $url): ?self
    {
        if (!str_starts_with(mb_strtolower(trim($label)), 'poptracker') || null === $url || '' === trim($url)) {
            return null;
        }
        $url = self::unwrapRedirect(trim($url));
        try {
            $source = TrackerPackSource::parse($url);
        } catch (\DomainException) {
            return new self(trim($label), $url, null, null);
        }

        return new self(trim($label), $url, $source->owner, $source->repo);
    }

    public function importable(): bool
    {
        return null !== $this->owner && null !== $this->repo;
    }

    /** The repository, imported from its latest release: a pinned tag in the catalog ages with the pack. */
    public function importUrl(): ?string
    {
        return $this->importable() ? sprintf('https://github.com/%s/%s', $this->owner, $this->repo) : null;
    }

    /** Whether this link points to the repository a pack was imported from (GitHub names ignore case). */
    public function sameRepository(string $sourceUrl): bool
    {
        if (!$this->importable()) {
            return false;
        }
        try {
            $source = TrackerPackSource::parse($sourceUrl);
        } catch (\DomainException) {
            return false;
        }

        return 0 === strcasecmp($source->owner, (string) $this->owner) && 0 === strcasecmp($source->repo, (string) $this->repo);
    }

    /** A sheet link pasted from a search can be Google's redirect: the real address is its `q` parameter. */
    private static function unwrapRedirect(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (false === $parts || !in_array($host, ['www.google.com', 'google.com'], true) || '/url' !== ($parts['path'] ?? '')) {
            return $url;
        }
        parse_str($parts['query'] ?? '', $query);
        $target = $query['q'] ?? null;

        return is_string($target) && '' !== $target ? $target : $url;
    }
}
