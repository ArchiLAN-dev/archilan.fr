<?php

declare(strict_types=1);

namespace App\Community\Application\Handler;

use App\Community\Application\Message\PostModerationActionToForumJob;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Support\ModerationForumDelivery;
use App\Community\Application\Support\ModerationForumMessageFactory;
use App\Community\Domain\Entity\ModerationAction;
use App\Community\Domain\Entity\ModerationCase;
use App\Community\Domain\Repository\ModerationActionRepositoryInterface;
use App\Community\Domain\Repository\ModerationCaseRepositoryInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Mirrors a recorded sanction in the member's case and its staff forum post (story 39.1): the first sanction
 * opens the post, the next ones are posted in it, a lift closes the case. Delivery and its failures: {@see ModerationForumDelivery}.
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
        private ModerationForumDelivery $delivery,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PostModerationActionToForumJob $job): void
    {
        if (!$this->delivery->isConfigured()) {
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

        $this->delivery->deliver($case, $memberName, $message, ['actionId' => $action->getId()]);
    }
}
