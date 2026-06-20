<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\ContentReport;
use App\Community\Domain\ContentReportRepositoryInterface;
use App\Community\Domain\ReportCategory;
use App\Community\Domain\ReportProblem;
use App\Community\Domain\ReportSeverity;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Lets a member report another member's profile with a structured category + problem + optional comment
 * (story 30.28). Fills the gap left by 30.10/30.13, which only wired comment reports. Idempotent per
 * (reporter, profile); self-reports are refused. A newly-created report may escalate the account.
 */
final readonly class ReportProfileService
{
    private const COMMENT_MAX = 500;

    public function __construct(
        private ContentReportRepositoryInterface $reports,
        private CommunityUserDirectoryQueryInterface $directory,
        private EvaluateAccountEscalation $escalation,
    ) {
    }

    /**
     * @return string 'ok' | 'not_found' | 'forbidden' | 'invalid'
     */
    public function report(string $reporterId, string $slug, string $category, string $problem, ?string $comment): string
    {
        if (!ReportCategory::isValid($category) || !ReportProblem::isValid($problem)) {
            return 'invalid';
        }

        $targetUserId = $this->directory->userIdForSlug($slug);
        if (null === $targetUserId) {
            return 'not_found';
        }
        if ($targetUserId === $reporterId) {
            return 'forbidden';
        }

        // Idempotent: one report per (reporter, profile). A repeat is a silent no-op.
        if ($this->reports->exists($reporterId, ContentReport::TARGET_PROFILE, $targetUserId)) {
            return 'ok';
        }

        $trimmed = null === $comment ? null : trim($comment);
        $cleanComment = null === $trimmed || '' === $trimmed ? null : mb_substr($trimmed, 0, self::COMMENT_MAX);
        // A human-readable reason keeps the legacy free-text column + admin search useful.
        $reason = sprintf('%s / %s', $category, $problem);

        try {
            $this->reports->save(ContentReport::create(
                $reporterId,
                ContentReport::TARGET_PROFILE,
                $targetUserId,
                $reason,
                new \DateTimeImmutable(),
                $category,
                $problem,
                $cleanComment,
            ));
        } catch (UniqueConstraintViolationException) {
            // Concurrent duplicate - idempotent, and no escalation for a report that didn't land.
            return 'ok';
        }

        $this->escalation->afterProfileReport($targetUserId, ReportSeverity::weight($problem));

        return 'ok';
    }
}
