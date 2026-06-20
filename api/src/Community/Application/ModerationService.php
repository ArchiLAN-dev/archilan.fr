<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\ContentReport;
use App\Community\Domain\ContentReportRepositoryInterface;
use App\Community\Domain\ProfileComment;
use App\Community\Domain\ProfileCommentRepositoryInterface;
use App\Community\Domain\ReportSeverity;

/**
 * Admin moderation (story 30.13): the report queue plus hide/restore of comments and report resolution.
 * Hiding is a soft-delete (keeps the trace); resolving closes the report regardless of the hide outcome.
 * Story 30.28 layers in structured category/problem/severity per report and a weighted "à examiner" list of
 * accounts whose unresolved profile reports cross the escalation threshold.
 */
final readonly class ModerationService
{
    public function __construct(
        private ContentReportRepositoryInterface $reports,
        private ProfileCommentRepositoryInterface $comments,
        private CommunityUserDirectoryQueryInterface $directory,
        private AdminReportsQueryInterface $reportsQuery,
        private AccountReportScoreQueryInterface $scores,
        private int $escalationThreshold,
    ) {
    }

    /**
     * @return array{
     *     count: int,
     *     threshold: int,
     *     flagged: list<array{userId: string, slug: string|null, displayName: string|null, avatarUrl: string|null, score: int, reportCount: int}>,
     *     reports: list<array{
     *         id: string, targetType: string, targetId: string, reason: string, createdAt: string,
     *         category: string, problem: string, note: string|null, severity: int, uncategorized: bool,
     *         reporter: array{slug: string, displayName: string|null, avatarUrl: string|null}|null,
     *         comment: array{id: string, body: string, hidden: bool, createdAt: string, author: array{slug: string, displayName: string|null, avatarUrl: string|null}|null, profileSlug: string|null}|null,
     *         profile: array{slug: string, displayName: string|null, avatarUrl: string|null}|null
     *     }>
     * }
     */
    public function list(ReportQueryFilters $filters): array
    {
        $ids = $this->reportsQuery->matchingIds($filters);
        $reports = $this->orderByIds($this->reports->findByIds($ids), $ids);

        return [
            'count' => $this->reports->countPending(),
            'threshold' => $this->escalationThreshold,
            'flagged' => $this->flaggedAccounts(),
            'reports' => $this->assemble($reports),
        ];
    }

    /**
     * Accounts whose unresolved profile reports sum (by severity) to at least the escalation threshold,
     * most severe first - the "à examiner" list (story 30.28).
     *
     * @return list<array{userId: string, slug: string|null, displayName: string|null, avatarUrl: string|null, score: int, reportCount: int}>
     */
    private function flaggedAccounts(): array
    {
        $byAccount = $this->scores->unresolvedProblemsByAccount();

        $scored = [];
        foreach ($byAccount as $userId => $problems) {
            $score = ReportSeverity::sum($problems);
            if ($score >= $this->escalationThreshold) {
                $scored[$userId] = ['score' => $score, 'reportCount' => count($problems)];
            }
        }
        if ([] === $scored) {
            return [];
        }

        $userIds = array_map(static fn (int|string $k): string => (string) $k, array_keys($scored));
        $cards = $this->directory->cards($userIds);

        $flagged = [];
        foreach ($scored as $key => $agg) {
            $userId = (string) $key;
            $card = $cards[$userId] ?? null;
            $flagged[] = [
                'userId' => $userId,
                'slug' => $card['slug'] ?? null,
                'displayName' => $card['displayName'] ?? null,
                'avatarUrl' => $card['avatarUrl'] ?? null,
                'score' => $agg['score'],
                'reportCount' => $agg['reportCount'],
            ];
        }

        usort($flagged, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $flagged;
    }

    /**
     * @param list<ContentReport> $reports
     *
     * @return list<array{
     *     id: string, targetType: string, targetId: string, reason: string, createdAt: string,
     *     category: string, problem: string, note: string|null, severity: int, uncategorized: bool,
     *     reporter: array{slug: string, displayName: string|null, avatarUrl: string|null}|null,
     *     comment: array{id: string, body: string, hidden: bool, createdAt: string, author: array{slug: string, displayName: string|null, avatarUrl: string|null}|null, profileSlug: string|null}|null,
     *     profile: array{slug: string, displayName: string|null, avatarUrl: string|null}|null
     * }>
     */
    private function assemble(array $reports): array
    {
        // Resolve the comments referenced by comment-type reports, then every user card in one batch.
        $commentIds = [];
        foreach ($reports as $report) {
            if (ContentReport::TARGET_COMMENT === $report->getTargetType()) {
                $commentIds[] = $report->getTargetId();
            }
        }
        $commentsById = $this->comments->findByIds(array_values(array_unique($commentIds)));

        $userIds = [];
        foreach ($reports as $report) {
            $userIds[] = $report->getReporterId();
            if (ContentReport::TARGET_PROFILE === $report->getTargetType()) {
                $userIds[] = $report->getTargetId();
            }
        }
        foreach ($commentsById as $comment) {
            $userIds[] = $comment->getAuthorId();
            $userIds[] = $comment->getProfileUserId();
        }
        $cards = [] === $userIds ? [] : $this->directory->cards(array_values(array_unique($userIds)));

        $items = [];
        foreach ($reports as $report) {
            $isComment = ContentReport::TARGET_COMMENT === $report->getTargetType();
            $comment = $isComment ? ($commentsById[$report->getTargetId()] ?? null) : null;

            $items[] = [
                'id' => $report->getId(),
                'targetType' => $report->getTargetType(),
                'targetId' => $report->getTargetId(),
                'reason' => $report->getReason(),
                'createdAt' => $report->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'category' => $report->getCategory(),
                'problem' => $report->getProblem(),
                'note' => $report->getComment(),
                'severity' => $report->severity(),
                'uncategorized' => $report->isUncategorized(),
                'reporter' => $this->card($cards, $report->getReporterId()),
                'comment' => null === $comment ? null : [
                    'id' => $comment->getId(),
                    'body' => $comment->getBody(),
                    'hidden' => $comment->isHidden(),
                    'createdAt' => $comment->getCreatedAt()->format(\DateTimeInterface::ATOM),
                    'author' => $this->card($cards, $comment->getAuthorId()),
                    'profileSlug' => $this->card($cards, $comment->getProfileUserId())['slug'] ?? null,
                ],
                'profile' => ContentReport::TARGET_PROFILE === $report->getTargetType()
                    ? $this->card($cards, $report->getTargetId())
                    : null,
            ];
        }

        return $items;
    }

    /**
     * @param list<ContentReport> $reports
     * @param list<string>        $ids
     *
     * @return list<ContentReport>
     */
    private function orderByIds(array $reports, array $ids): array
    {
        $byId = [];
        foreach ($reports as $report) {
            $byId[$report->getId()] = $report;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    public function hideComment(string $commentId): string
    {
        $comment = $this->comments->findById($commentId);
        if (!$comment instanceof ProfileComment) {
            return 'not_found';
        }

        $comment->hide(new \DateTimeImmutable());
        $this->comments->flush();

        return 'ok';
    }

    public function restoreComment(string $commentId): string
    {
        $comment = $this->comments->findById($commentId);
        if (!$comment instanceof ProfileComment) {
            return 'not_found';
        }

        $comment->restore();
        $this->comments->flush();

        return 'ok';
    }

    public function resolveReport(string $reportId, string $adminId): string
    {
        $report = $this->reports->findById($reportId);
        if (!$report instanceof ContentReport) {
            return 'not_found';
        }

        $report->resolve($adminId, new \DateTimeImmutable());
        $this->reports->flush();

        return 'ok';
    }

    /**
     * @param array<string, array{userId: string, slug: string, displayName: string|null, avatarUrl: string|null}> $cards
     *
     * @return array{slug: string, displayName: string|null, avatarUrl: string|null}|null
     */
    private function card(array $cards, string $userId): ?array
    {
        $card = $cards[$userId] ?? null;
        if (null === $card) {
            return null;
        }

        return ['slug' => $card['slug'], 'displayName' => $card['displayName'], 'avatarUrl' => $card['avatarUrl']];
    }
}
