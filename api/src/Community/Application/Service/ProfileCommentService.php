<?php

declare(strict_types=1);

namespace App\Community\Application\Service;

use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Support\Notifier;
use App\Community\Application\Support\ProfileVisibility;
use App\Community\Domain\Entity\ContentReport;
use App\Community\Domain\Entity\Notification;
use App\Community\Domain\Entity\ProfileComment;
use App\Community\Domain\Repository\BlockRepositoryInterface;
use App\Community\Domain\Repository\ContentReportRepositoryInterface;
use App\Community\Domain\Repository\ProfileCommentRepositoryInterface;
use App\Community\Domain\ValueObject\ReportCategory;
use App\Membership\Application\Query\ActiveMembershipQueryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * Profile guestbook (story 30.10). Two audiences: *viewing* follows the profile audience; *writing*
 * requires a live member (or the owner) who isn't blocked. Soft-hide on owner removal, report -> queue,
 * and a simple per-author rate limit.
 */
final readonly class ProfileCommentService
{
    private const int BODY_MAX = 2000;
    private const int RATE_MAX = 5;
    private const int RATE_WINDOW_SECONDS = 60;

    public function __construct(
        private ProfileCommentRepositoryInterface $comments,
        private ContentReportRepositoryInterface $reports,
        private ProfileVisibility $visibility,
        private ActiveMembershipQueryInterface $memberships,
        private BlockRepositoryInterface $blocks,
        private CommunityUserDirectoryQueryInterface $directory,
        private Notifier $notifier,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{status: string, comments: list<array<string, mixed>>}
     */
    public function list(string $ownerId, ?string $viewerId, int $limit): array
    {
        if (!$this->visibility->canSee($viewerId, $ownerId)) {
            return ['status' => 'forbidden', 'comments' => []];
        }

        $comments = $this->comments->visibleForProfile($ownerId, $limit > 0 ? min($limit, 100) : 50);
        $cards = $this->directory->cards(array_values(array_unique(array_map(
            static fn (ProfileComment $c): string => $c->getAuthorId(),
            $comments,
        ))));

        $items = [];
        foreach ($comments as $comment) {
            $card = $cards[$comment->getAuthorId()] ?? null;
            $items[] = [
                'id' => $comment->getId(),
                'body' => $comment->getBody(),
                'createdAt' => $comment->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'author' => null === $card ? null : [
                    'slug' => $card['slug'],
                    'displayName' => $card['displayName'],
                    'avatarUrl' => $card['avatarUrl'],
                ],
                'canDelete' => null !== $viewerId && ($comment->isAuthor($viewerId) || $comment->isOnProfileOf($viewerId)),
            ];
        }

        return ['status' => 'ok', 'comments' => $items];
    }

    /**
     * @return array{status: string}
     */
    public function post(string $ownerId, string $writerId, string $body): array
    {
        $body = trim($body);
        if ('' === $body) {
            return ['status' => 'empty'];
        }
        if (mb_strlen($body) > self::BODY_MAX) {
            return ['status' => 'too_long'];
        }
        if ($this->blocks->existsEitherWay($writerId, $ownerId)) {
            return ['status' => 'blocked'];
        }
        if (!$this->visibility->canSee($writerId, $ownerId)) {
            return ['status' => 'forbidden'];
        }
        if ($writerId !== $ownerId && !$this->memberships->hasActiveMembership($writerId)) {
            return ['status' => 'forbidden'];
        }

        $since = $this->clock->now()->modify(sprintf('-%d seconds', self::RATE_WINDOW_SECONDS));
        if ($this->comments->countByAuthorSince($writerId, $since) >= self::RATE_MAX) {
            return ['status' => 'rate_limited'];
        }

        $this->comments->save(ProfileComment::create($ownerId, $writerId, $body, $this->clock->now()));
        $this->notifier->notify($ownerId, Notification::TYPE_COMMENT_RECEIVED, ['fromUserId' => $writerId]);

        return ['status' => 'ok'];
    }

    public function delete(string $commentId, string $userId): string
    {
        $comment = $this->comments->findById($commentId);
        if (!$comment instanceof ProfileComment) {
            return 'not_found';
        }

        if ($comment->isAuthor($userId)) {
            $this->comments->remove($comment);

            return 'ok';
        }
        if ($comment->isOnProfileOf($userId)) {
            $comment->hide($this->clock->now());
            $this->comments->flush();

            return 'ok';
        }

        return 'forbidden';
    }

    public function report(string $commentId, string $userId, string $reason): string
    {
        $comment = $this->comments->findById($commentId);
        if (!$comment instanceof ProfileComment) {
            return 'not_found';
        }

        if (!$this->reports->exists($userId, ContentReport::TARGET_COMMENT, $commentId)) {
            $trimmed = trim($reason);
            try {
                $this->reports->save(ContentReport::create(
                    $userId,
                    ContentReport::TARGET_COMMENT,
                    $commentId,
                    '' === $trimmed ? 'inappropriate' : mb_substr($trimmed, 0, 500),
                    $this->clock->now(),
                    ReportCategory::COMMENT,
                ));
            } catch (UniqueConstraintViolationException) {
                // Concurrent duplicate report - idempotent.
            }
        }

        return 'ok';
    }
}
