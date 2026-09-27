<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Application\Exception\ModerationForumDeliveryException;
use App\Community\Application\Exception\ModerationForumTemporarilyUnavailableException;
use App\Community\Application\Port\ModerationForumInterface;
use App\Community\Domain\Entity\ModerationCase;
use App\Community\Domain\Repository\ModerationCaseRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Brings one message to a member's post in the staff forum (stories 39.1 and 39.2): opens the post on the
 * case's first message, posts in it afterwards, and commits the case whatever happens to the post.
 *
 * A passing failure (rate limit, Discord down) goes back to Messenger, which retries it with a growing delay
 * then parks it in the failure transport. A definitive refusal (missing permission) is logged and dropped:
 * what it reports is already committed on the site, and a forum is no reason to undo it.
 */
final readonly class ModerationForumDelivery
{
    public function __construct(
        private ModerationForumInterface $forum,
        private ModerationCaseRepositoryInterface $cases,
        private LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->forum->isConfigured();
    }

    /**
     * @param array<string, string> $logContext what the log line names if the forum refuses the message
     */
    public function deliver(ModerationCase $case, string $memberName, ModerationForumMessage $message, array $logContext): void
    {
        try {
            $threadId = $case->getForumThreadId();
            if (null === $threadId) {
                $case->attachForumThread($this->forum->openThread($memberName, $message));
            } else {
                $this->forum->post($threadId, $message);
            }
        } catch (ModerationForumDeliveryException $e) {
            // The case's own state moved anyway: keep it, whatever happens to the post.
            $this->cases->flush();
            if ($e->transient) {
                throw new ModerationForumTemporarilyUnavailableException($e->getMessage(), 0, $e);
            }
            $this->logger->warning('moderation_forum.not_posted', [
                ...$logContext,
                'targetUserId' => $case->getTargetUserId(),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->cases->flush();
    }
}
