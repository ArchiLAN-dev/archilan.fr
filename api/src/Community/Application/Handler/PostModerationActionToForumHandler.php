<?php

declare(strict_types=1);

namespace App\Community\Application\Handler;

use App\Community\Application\Exception\DiscordServerSanctionException;
use App\Community\Application\Exception\DiscordServerTemporarilyUnavailableException;
use App\Community\Application\Message\PostModerationActionToForumJob;
use App\Community\Application\Port\DiscordServerSanctionsInterface;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Support\MemberDirectMessenger;
use App\Community\Application\Support\ModerationForumDelivery;
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
 * opens the post, the next ones are posted in it, a lift closes the case. Delivery and its failures:
 * {@see ModerationForumDelivery}.
 *
 * Story 39.4: the member is told by the bot's direct message first, and the forum says how that went. The
 * case is kept up to date even without a forum, since it carries the DM channel the answers are read from.
 *
 * Story 39.5: a ban is then applied on the Discord server, a lift unbans - after the direct message, while the
 * bot still shares a server with the member. Each outcome is committed as soon as it is known, so a retry
 * (Discord down) never sends the message nor bans twice; the forum is posted once both are settled.
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
        private DiscordServerSanctionsInterface $server,
        private ModerationForumDelivery $delivery,
        private ClockInterface $clock,
        private LoggerInterface $logger,
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

        if (null === $action->getDiscordServerStatus()) {
            $serverStatus = $this->applyOnServer($action, $discordId);
            if (null !== $serverStatus) {
                $action->recordServerSanction($serverStatus);
                $this->cases->flush();
            }
        }

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
            $action->getDiscordServerStatus(),
        );

        $this->delivery->deliver($case, $memberName, $message, ['actionId' => $action->getId()]);
    }

    /**
     * Story 39.5: a ban bans, a lift unbans; the other sanctions leave the server alone (null). A suspension is
     * story 39.6's.
     *
     * @return string|null one of the {@see ModerationAction} SERVER_ outcomes
     */
    private function applyOnServer(ModerationAction $action, ?string $discordId): ?string
    {
        $ban = ModerationAction::ACTION_BAN === $action->getAction();
        if (!$ban && ModerationAction::ACTION_LIFT !== $action->getAction()) {
            return null;
        }
        if (null === $discordId) {
            return ModerationAction::SERVER_NOT_LINKED;
        }
        if (!$this->server->isConfigured()) {
            return ModerationAction::SERVER_UNAVAILABLE;
        }

        try {
            if ($ban) {
                $this->server->ban($discordId, $action->getReason());
            } else {
                $this->server->unban($discordId);
            }
        } catch (DiscordServerSanctionException $e) {
            if ($e->transient) {
                throw new DiscordServerTemporarilyUnavailableException($e->getMessage(), 0, $e);
            }
            $this->logger->warning('moderation_server.not_applied', [
                'actionId' => $action->getId(),
                'targetUserId' => $action->getTargetUserId(),
                'error' => $e->getMessage(),
            ]);

            return ModerationAction::SERVER_FAILED;
        }

        return $ban ? ModerationAction::SERVER_BANNED : ModerationAction::SERVER_UNBANNED;
    }
}
