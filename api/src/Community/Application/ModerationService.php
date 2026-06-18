<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\ContentReport;
use App\Community\Domain\ContentReportRepositoryInterface;
use App\Community\Domain\ProfileComment;
use App\Community\Domain\ProfileCommentRepositoryInterface;

/**
 * Admin moderation (story 30.13): the report queue plus hide/restore of comments and report resolution.
 * Hiding is a soft-delete (keeps the trace); resolving closes the report regardless of the hide outcome.
 */
final readonly class ModerationService
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function __construct(
        private ContentReportRepositoryInterface $reports,
        private ProfileCommentRepositoryInterface $comments,
        private CommunityUserDirectoryQueryInterface $directory,
    ) {
    }

    /**
     * @return array{
     *     count: int,
     *     reports: list<array{
     *         id: string, targetType: string, targetId: string, reason: string, createdAt: string,
     *         reporter: array{slug: string, displayName: string|null, avatarUrl: string|null}|null,
     *         comment: array{id: string, body: string, hidden: bool, createdAt: string, author: array{slug: string, displayName: string|null, avatarUrl: string|null}|null, profileSlug: string|null}|null,
     *         profile: array{slug: string, displayName: string|null, avatarUrl: string|null}|null
     *     }>
     * }
     */
    public function queue(int $limit): array
    {
        $reports = $this->reports->pending($this->clampLimit($limit));

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

        return ['count' => $this->reports->countPending(), 'reports' => $items];
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

    private function clampLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }
}
