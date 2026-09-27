<?php

declare(strict_types=1);

namespace App\Community\Application\Handler;

use App\Community\Application\Message\PostModerationActionToForumJob;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Support\MemberDirectMessenger;
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
 * opens the post, the next ones are posted in it, a lift closes the case. Delivery and its failures:
 * {@see ModerationForumDelivery}.
 *
 * Story 39.4: the member is told by the bot's direct message first, and the forum says how that went. The
 * case is kept up to date even without a forum, since it carries the DM channel the answers are read from. The
 * outcome is committed before the forum is tried, so a retry never sends the member the message twice.
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
        private MemberDirectMessenger $directMessages,
        private ModerationForumDelivery $delivery,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PostModerationActionToForumJob $job): void
    {
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

        $discordId = $this->members->discordIdOf($targetId);
        $suspendedUntil = $this->members->currentState($targetId)?->suspendedUntil;
        if (null === $action->getDiscordDmStatus()) {
            $action->recordDirectMessage($this->directMessages->send(
                $case,
                $discordId,
                $this->messages->forSanctionDirectMessage($action, $suspendedUntil),
                ['actionId' => $action->getId()],
            ));
        }
        $this->cases->flush();

        if (!$this->delivery->isConfigured()) {
            return;
        }

        $names = $this->directory->namesFor([$targetId, $action->getActorId()]);
        $memberName = $names[$targetId] ?? $targetId;
        $message = $this->messages->forAction(
            $action,
            $memberName,
            $discordId,
            $names[$action->getActorId()] ?? 'Un admin',
            $suspendedUntil,
            $action->getDiscordDmStatus(),
        );

        $this->delivery->deliver($case, $memberName, $message, ['actionId' => $action->getId()]);
    }
}
