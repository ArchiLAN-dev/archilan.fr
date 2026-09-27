<?php

declare(strict_types=1);

namespace App\Community\Application\Handler;

use App\Community\Application\Exception\ModerationForumDeliveryException;
use App\Community\Application\Exception\ModerationForumTemporarilyUnavailableException;
use App\Community\Application\Message\PostModerationActionToForumJob;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Port\ModerationForumInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Support\ModerationForumMessageFactory;
use App\Community\Domain\Entity\ModerationAction;
use App\Community\Domain\Entity\ModerationCase;
use App\Community\Domain\Repository\ModerationActionRepositoryInterface;
use App\Community\Domain\Repository\ModerationCaseRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Mirrors a recorded sanction in the member's case and its staff forum post (story 39.1): the first sanction
 * opens the post, the next ones are posted in it, a lift closes the case.
 *
 * A passing failure (rate limit, Discord down) goes back to Messenger, which retries it with a growing delay
 * then parks it in the failure transport. A definitive refusal (missing permission) is logged and dropped:
 * the sanction itself is already committed, and a forum is no reason to undo it.
 */
#[AsMessageHandler]
final readonly class PostModerationActionToForumHandler
{
    public function __construct(
        private ModerationActionRepositoryInterface $actions,
        private ModerationCaseRepositoryInterface $cases,
        private MemberModerationGatewayInterface $members,
        private CommunityUserDirectoryQueryInterface $directory,
        private ModerationForumMessageFactory $messages,
        private ModerationForumInterface $forum,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PostModerationActionToForumJob $job): void
    {
        if (!$this->forum->isConfigured()) {
            return;
        }

        $action = $this->actions->findById($job->actionId);
        if (null === $action) {
            return;
        }

        $targetId = $action->getTargetUserId();
        $now = $this->clock->now();
        $case = $this->cases->findByTargetUserId($targetId);
        if (null === $case) {
            $case = ModerationCase::open($targetId, $now);
            $this->cases->save($case);
        } elseif (ModerationAction::ACTION_LIFT !== $action->getAction()) {
            $case->reopen($now);
        }
        if (ModerationAction::ACTION_LIFT === $action->getAction()) {
            $case->close($now);
        }

        $names = $this->directory->namesFor([$targetId, $action->getActorId()]);
        $memberName = $names[$targetId] ?? $targetId;
        $message = $this->messages->forAction(
            $action,
            $memberName,
            $this->members->discordIdOf($targetId),
            $names[$action->getActorId()] ?? 'Un admin',
            $this->members->currentState($targetId)?->suspendedUntil,
        );

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
                'actionId' => $action->getId(),
                'targetUserId' => $targetId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->cases->flush();
    }
}
