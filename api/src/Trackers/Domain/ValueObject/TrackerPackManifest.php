<?php

declare(strict_types=1);

namespace App\Trackers\Domain\ValueObject;

/**
 * The `manifest.json` of a PopTracker pack, reduced to what the site keeps (story 54.1). A pack without variants
 * gets one implicit variant, as PopTracker does.
 */
final readonly class TrackerPackManifest
{
    public const int MAX_UID = 120;
    public const int MAX_NAME = 200;
    public const int MAX_VERSION = 64;
    public const int MAX_VARIANTS = 50;

    /** @param non-empty-list<TrackerPackVariant> $variants */
    private function __construct(
        public string $packageUid,
        public string $name,
        public ?string $packageVersion,
        public ?string $author,
        public ?string $gameName,
        public array $variants,
        public ?string $minPopTrackerVersion,
        public ?string $targetPopTrackerVersion,
        public ?string $versionsUrl,
    ) {
    }

    /**
     * @param array<mixed> $data the decoded manifest
     *
     * @throws \DomainException tracker_pack_manifest_invalid
     */
    public static function fromArray(array $data): self
    {
        $uid = self::text($data, 'package_uid', self::MAX_UID);
        $name = self::text($data, 'name', self::MAX_NAME);
        if (null === $uid || null === $name) {
            throw new \DomainException('tracker_pack_manifest_invalid');
        }

        $versionsUrl = self::text($data, 'versions_url', 2000);
        if (null !== $versionsUrl && !str_starts_with($versionsUrl, 'https://')) {
            $versionsUrl = null;
        }

        return new self(
            $uid,
            $name,
            self::text($data, 'package_version', self::MAX_VERSION),
            self::text($data, 'author', self::MAX_NAME),
            self::text($data, 'game_name', self::MAX_NAME),
            self::variants($data['variants'] ?? null),
            self::text($data, 'min_poptracker_version', self::MAX_VERSION),
            self::text($data, 'target_poptracker_version', self::MAX_VERSION),
            $versionsUrl,
        );
    }

    public function hasArchipelagoVariant(): bool
    {
        return array_any($this->variants, fn ($variant) => $variant->supportsArchipelago());
    }

    /** @return list<array{uid: string, displayName: string, flags: list<string>}> */
    public function variantsAsArray(): array
    {
        return array_map(static fn (TrackerPackVariant $v): array => $v->toArray(), $this->variants);
    }

    /**
     * A trimmed string field, or null when absent or empty.
     *
     * @param array<mixed> $data
     *
     * @throws \DomainException tracker_pack_manifest_invalid when present but not a string, or too long
     */
    private static function text(array $data, string $key, int $max): ?string
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            throw new \DomainException('tracker_pack_manifest_invalid');
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new \DomainException('tracker_pack_manifest_invalid');
        }

        return '' === $value ? null : $value;
    }

    /**
     * @return non-empty-list<TrackerPackVariant>
     *
     * @throws \DomainException tracker_pack_manifest_invalid
     */
    private static function variants(mixed $raw): array
    {
        if (null === $raw || [] === $raw) {
            return [new TrackerPackVariant('', 'Standard', [])];
        }
        if (!is_array($raw) || array_is_list($raw) || count($raw) > self::MAX_VARIANTS) {
            throw new \DomainException('tracker_pack_manifest_invalid');
        }

        $variants = [];
        foreach ($raw as $uid => $definition) {
            $uid = (string) $uid;
            if (!is_array($definition) || mb_strlen($uid) > self::MAX_UID) {
                throw new \DomainException('tracker_pack_manifest_invalid');
            }
            $displayName = self::text($definition, 'display_name', self::MAX_NAME) ?? $uid;
            $flags = $definition['flags'] ?? [];
            if (!is_array($flags) || !array_is_list($flags)) {
                throw new \DomainException('tracker_pack_manifest_invalid');
            }
            $variants[] = new TrackerPackVariant(
                $uid,
                $displayName,
                array_values(array_unique(array_filter($flags, static fn (mixed $f): bool => is_string($f) && '' !== $f && strlen($f) <= 64))),
            );
        }

        return $variants;
    }
}
