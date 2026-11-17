<?php

declare(strict_types=1);

namespace App\Trackers\Application\Query;

/** A game's pack as the admin sees it (stories 54.1 to 54.3). */
final readonly class AdminTrackerPackView
{
    /**
     * @param list<array{uid: string, displayName: string, flags: list<string>}>                                                                                                                                                                   $variants
     * @param array{packageVersion: string, downloadUrl: string, sha256: ?string, changelog: list<string>, gitTag: ?string}|null                                                                                                                   $availableUpdate
     * @param list<array{id: string, packageVersion: ?string, gitTag: ?string, sizeBytes: int, importedAt: string, rootPath: string, sha256: string, variants: list<array{uid: string, displayName: string, flags: list<string>}>, current: bool}> $versions        the kept versions, newest first (54.8: with what the trial needs to open one)
     * @param list<array{variantUid: string, status: string, errors: list<array{kind: string, message: string, file: ?string, line: ?int}>, unsupportedApis: list<string>, engineVersion: int, reportedAt: string}>                                $compatibility   story 54.8: the last try of each variant of the served version
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $author,
        public string $packageUid,
        public ?string $gameName,
        public string $sourceUrl,
        public ?string $licenseSpdx,
        public bool $hasApVariant,
        public string $versionId,
        public ?string $packageVersion,
        public ?string $gitTag,
        public int $sizeBytes,
        public array $variants,
        public ?string $minPopTrackerVersion,
        public string $importedAt,
        public bool $enabled,
        public bool $isDefault,
        public ?string $defaultVariantUid,
        public bool $licenseAcknowledged,
        public ?array $availableUpdate,
        public ?string $lastCheckedAt,
        public ?string $lastCheckError,
        public array $versions,
        public string $rootPath = '',
        public string $sha256 = '',
        public array $compatibility = [],
    ) {
    }
}
