<?php

declare(strict_types=1);

namespace App\Community\Application\Command;

use App\Community\Application\Query\RunListingReportQueryInterface;
use App\Community\Domain\Entity\ContentReport;
use App\Community\Domain\Repository\ContentReportRepositoryInterface;
use App\Community\Domain\ValueObject\ReportCategory;
use App\Community\Domain\ValueObject\ReportProblem;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * Lets a member report a run listing (story 43.17): its message is free text written for every member. Same rules
 * as a profile report (story 30.28): one report per (reporter, listing), a repeat is a silent no-op, and the owner
 * cannot report their own listing.
 */
final readonly class ReportRunListingService
{
    private const int COMMENT_MAX = 500;

    public function __construct(
        private ContentReportRepositoryInterface $reports,
        private RunListingReportQueryInterface $listings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return string 'ok' | 'not_found' | 'forbidden' | 'invalid'
     */
    public function report(string $reporterId, string $runId, string $problem, ?string $comment): string
    {
        if (!ReportProblem::isValid($problem)) {
            return 'invalid';
        }
        $listing = $this->listings->listed($runId);
        if (null === $listing) {
            return 'not_found';
        }
        if ($listing['ownerId'] === $reporterId) {
            return 'forbidden';
        }
        if ($this->reports->exists($reporterId, ContentReport::TARGET_RUN_LISTING, $runId)) {
            return 'ok';
        }

        $trimmed = null === $comment ? null : trim($comment);
        try {
            $this->reports->save(ContentReport::create(
                $reporterId,
                ContentReport::TARGET_RUN_LISTING,
                $runId,
                sprintf('%s / %s', ReportCategory::OTHER, $problem),
                $this->clock->now(),
                ReportCategory::OTHER,
                $problem,
                null === $trimmed || '' === $trimmed ? null : mb_substr($trimmed, 0, self::COMMENT_MAX),
                // Story 43.18: the listing as reported, kept even if its owner edits or takes it down.
                ['title' => $listing['title'], 'pitch' => $listing['pitch']],
            ));
        } catch (UniqueConstraintViolationException) {
            return 'ok';
        }

        return 'ok';
    }
}
