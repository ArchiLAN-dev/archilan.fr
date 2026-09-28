<?php

declare(strict_types=1);

namespace App\Community\Application\Handler;

use App\Community\Application\Exception\DiscordServerSanctionException;
use App\Community\Application\Exception\DiscordServerTemporarilyUnavailableException;
use App\Community\Application\Exception\MemberDirectMessageTemporarilyUnavailableException;
use App\Community\Application\Message\PostModerationActionToForumJob;
use App\Community\Application\Port\DiscordServerSanctionsInterface;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Support\DiscordTimeoutWindow;
use App\Community\Application\Support\MemberDirectMessenger;
use App\Community\Application\Support\ModerationForumDelivery;
use App\Community\Application\Support\ModerationForumMessageFactory;
use App\Community\Domain\Entity\ModerationAction;
use App\Community\Domain\Entity\ModerationCase;
use App\Community\Domain\Entity\ModerationCaseMessage;
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
 * bot still shares a server with the member. Story 39.6: a suspension times the member out, a lift also ends
 * the timeout.
 *
 * Story 39.9: a ban or a suspension already lifted or over when its job runs (a retry overtaken by the lift)
 * is neither told nor applied, and does not reopen the case. A passing failure of the message never holds the
 * sanction back: the server step runs, then the message is retried. Each outcome is committed as soon as it is known, so a retry
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
        $state = $this->members->currentState($targetId);
        $suspendedUntil = $state?->suspendedUntil;
        $inEffect = $this->inEffect($action, $state?->bannedAt, $suspendedUntil, $now);

        $case = $this->cases->findByTargetUserId($targetId);
        if (null === $case) {
            $case = ModerationCase::open($targetId, $now);
            $this->cases->save($case);
        } elseif (ModerationAction::ACTION_LIFT !== $action->getAction() && $action->isSanction() && $inEffect) {
            // A note (story 39.10) is filed in the case as it stands: it reopens nothing.
            $case->reopen($now);
        }
        if (ModerationAction::ACTION_LIFT === $action->getAction()) {
            $case->close($now);
        }

        $discordId = $this->members->discordIdOf($targetId);
        $retryMessage = null;
        if (null === $action->getDiscordDmStatus()) {
            try {
                $action->recordDirectMessage(match (true) {
                    // Story 39.10: a note is for the staff only.
                    !$action->isSanction() => ModerationCaseMessage::DM_INTERNAL,
                    $inEffect => $this->directMessages->send($case, $discordId, $this->messages->forSanctionDirectMessage($action, $suspendedUntil), ['actionId' => $action->getId()]),
                    default => ModerationCaseMessage::DM_SUPERSEDED,
                });
            } catch (MemberDirectMessageTemporarilyUnavailableException $e) {
                $retryMessage = $e;
            }
        }
        $this->cases->flush();

        if (null === $action->getDiscordServerStatus()) {
            $serverStatus = $inEffect ? $this->applyOnServer($action, $discordId, $suspendedUntil) : ModerationAction::SERVER_SUPERSEDED;
            if (null !== $serverStatus) {
                $action->recordServerSanction($serverStatus);
                $this->cases->flush();
            }
        }

        if (null !== $retryMessage) {
            throw $retryMessage;
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
            ModerationAction::ACTOR_DISCORD === $action->getActorId() ? 'Discord' : ($names[$action->getActorId()] ?? 'Un admin'),
            $suspendedUntil,
            $action->getDiscordDmStatus(),
            $action->getDiscordServerStatus(),
        );

        $this->delivery->deliver($case, $memberName, $message, ['actionId' => $action->getId()]);
    }

    /**
     * Story 39.9: a ban still standing, a suspension still running. A warning and a lift always stand.
     */
    private function inEffect(ModerationAction $action, ?string $bannedAt, ?string $suspendedUntil, \DateTimeImmutable $now): bool
    {
        return match ($action->getAction()) {
            ModerationAction::ACTION_BAN => null !== $bannedAt,
            ModerationAction::ACTION_SUSPEND => null !== $suspendedUntil && new \DateTimeImmutable($suspendedUntil) > $now,
            default => true,
        };
    }

    /**
     * A ban bans (story 39.5), a suspension times out (story 39.6), a lift unbans and ends the timeout; a
     * warning leaves the server alone (null).
     *
     * @return string|null one of the {@see ModerationAction} SERVER_ outcomes
     */
    private function applyOnServer(ModerationAction $action, ?string $discordId, ?string $suspendedUntil): ?string
    {
        $kind = $action->getAction();
        if (ModerationAction::ACTION_WARN === $kind || ModerationAction::ACTION_NOTE === $kind || (ModerationAction::ACTION_SUSPEND === $kind && null === $suspendedUntil)) {
            return null;
        }
        if (null === $discordId) {
            return ModerationAction::SERVER_NOT_LINKED;
        }
        if (!$this->server->isConfigured()) {
            return ModerationAction::SERVER_UNAVAILABLE;
        }

        // Story 39.7: already banned there. Banning again would overwrite the reason with the site's prefix,
        // and the ban would then pass for one of the site's.
        if (ModerationAction::ACTION_BAN === $kind && ModerationAction::ACTOR_DISCORD === $action->getActorId()) {
            return ModerationAction::SERVER_BANNED;
        }

        try {
            switch ($kind) {
                case ModerationAction::ACTION_BAN:
                    $this->server->ban($discordId, $action->getReason());

                    return ModerationAction::SERVER_BANNED;
                case ModerationAction::ACTION_SUSPEND:
                    return $this->server->timeout($discordId, DiscordTimeoutWindow::until($suspendedUntil, $this->clock->now()), $action->getReason())
                        ? ModerationAction::SERVER_TIMED_OUT
                        : ModerationAction::SERVER_NOT_MEMBER;
                default:
                    $this->server->unban($discordId);
                    $this->server->clearTimeout($discordId);

                    return ModerationAction::SERVER_LIFTED;
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
    }
}
