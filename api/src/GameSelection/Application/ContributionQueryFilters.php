<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\GameTutorialContribution;

/**
 * Normalized, validated filters for the admin tutorial-contributions listing (story 31.9). Built from raw
 * request input via {@see self::fromRaw()} so unknown values fall back to safe defaults (pending, recent).
 */
final readonly class ContributionQueryFilters
{
    public const STATUS_ALL = 'all';

    public const TARGET_ANY = 'any';
    public const TARGET_LISTED = 'listed';
    public const TARGET_UNLISTED = 'unlisted';

    public const SORT_RECENT = 'recent';
    public const SORT_OLDEST = 'oldest';

    public function __construct(
        public string $status,
        public string $target,
        public string $sort,
        public string $search,
    ) {
    }

    public static function fromRaw(?string $status, ?string $target, ?string $sort, ?string $search): self
    {
        $statuses = [
            GameTutorialContribution::STATUS_PENDING,
            GameTutorialContribution::STATUS_APPROVED,
            GameTutorialContribution::STATUS_REJECTED,
            self::STATUS_ALL,
        ];

        return new self(
            in_array($status, $statuses, true) ? $status : GameTutorialContribution::STATUS_PENDING,
            in_array($target, [self::TARGET_LISTED, self::TARGET_UNLISTED], true) ? $target : self::TARGET_ANY,
            self::SORT_OLDEST === $sort ? self::SORT_OLDEST : self::SORT_RECENT,
            trim($search ?? ''),
        );
    }
}
