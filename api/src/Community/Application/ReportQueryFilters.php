<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\ContentReport;
use App\Community\Domain\ReportProblem;

/**
 * Normalized, validated filters for the admin moderation reports listing (story 30.25). Built from raw
 * request input via {@see self::fromRaw()} so unknown values fall back to safe defaults (pending, recent).
 * Story 30.28 adds a problem filter, an "uncategorized only" toggle (the low-signal Autre/Autre bucket),
 * and a severity sort (most problematic first).
 */
final readonly class ReportQueryFilters
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_ALL = 'all';

    public const COMMENT_ANY = 'any';
    public const COMMENT_HIDDEN = 'hidden';
    public const COMMENT_VISIBLE = 'visible';

    public const TARGET_ANY = 'any';

    public const PROBLEM_ANY = 'any';

    public const SORT_RECENT = 'recent';
    public const SORT_OLDEST = 'oldest';
    public const SORT_SEVERITY = 'severity';

    private const MAX_LIMIT = 100;

    public function __construct(
        public string $status,
        public string $commentState,
        public string $targetType,
        public string $sort,
        public string $search,
        public int $limit,
        public string $problem = self::PROBLEM_ANY,
        public bool $uncategorizedOnly = false,
    ) {
    }

    public static function fromRaw(
        ?string $status,
        ?string $commentState,
        ?string $targetType,
        ?string $sort,
        ?string $search,
        int $limit,
        ?string $problem = null,
        bool $uncategorizedOnly = false,
    ): self {
        return new self(
            in_array($status, [self::STATUS_PENDING, self::STATUS_RESOLVED, self::STATUS_ALL], true) ? $status : self::STATUS_PENDING,
            in_array($commentState, [self::COMMENT_HIDDEN, self::COMMENT_VISIBLE], true) ? $commentState : self::COMMENT_ANY,
            in_array($targetType, [ContentReport::TARGET_COMMENT, ContentReport::TARGET_PROFILE], true) ? $targetType : self::TARGET_ANY,
            in_array($sort, [self::SORT_OLDEST, self::SORT_SEVERITY], true) ? $sort : self::SORT_RECENT,
            trim($search ?? ''),
            max(1, min(self::MAX_LIMIT, $limit)),
            null !== $problem && ReportProblem::isValid($problem) ? $problem : self::PROBLEM_ANY,
            $uncategorizedOnly,
        );
    }
}
