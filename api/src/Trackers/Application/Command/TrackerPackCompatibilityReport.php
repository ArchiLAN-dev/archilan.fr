<?php

declare(strict_types=1);

namespace App\Trackers\Application\Command;

use App\Trackers\Domain\Enum\TrackerPackCompatibilityStatus;

/** What the site's tracker found when an admin tried one variant of a pack (story 54.8). */
final readonly class TrackerPackCompatibilityReport
{
    /**
     * @param list<array{kind: string, message: string, file: ?string, line: ?int}> $errors
     * @param list<string>                                                          $unsupportedApis
     */
    public function __construct(
        public string $variantUid,
        public TrackerPackCompatibilityStatus $status,
        public array $errors,
        public array $unsupportedApis,
        public int $engineVersion,
    ) {
    }
}
