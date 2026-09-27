<?php

declare(strict_types=1);

namespace App\Community\Application\Handler;

use App\Community\Application\Exception\MemberDirectMessageException;
use App\Community\Application\Exception\MemberDirectMessageTemporarilyUnavailableException;
use App\Community\Application\Message\DeliverStaffReplyJob;
use App\Community\Application\Port\MemberDirectMessageInterface;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Support\ModerationForumDelivery;
use App\Community\Application\Support\ModerationForumMessageFactory;
use App\Community\Domain\Entity\ModerationCaseMessage;
use App\Community\Domain\Repository\ModerationCaseMessageRepositoryInterface;
use App\Community\Domain\Repository\ModerationCaseRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Brings a staff reply to the member, by the bot's direct message, then to the staff forum with the outcome of
 * that message (story 39.3).
 *
 * The outcome is committed before the forum is tried, so a retry of the job (forum down) never sends the
 * member the same message twice. A passing DM failure goes back to Messenger with nothing recorded; a closed
 * DM is recorded and reported, not retried.
 */
#[AsMessageHandler]
final readonly class DeliverStaffReplyHandler
{
    public function __construct(
        private ModerationCaseMessageRepositoryInterface $messages,
        private ModerationCaseRepositoryInterface $cases,
        private MemberModerationGatewayInterface $members,
        private CommunityUserDirectoryQueryInterface $directory,
        private ModerationForumMessageFactory $forumMessages,
        private MemberDirectMessageInterface $directMessages,
        private ModerationForumDelivery $forum,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeliverStaffReplyJob $job): void
    {
        $reply = $this->messages->findById($job->messageId);
        $case = null !== $reply ? $this->cases->findById($reply->getCaseId()) : null;
        if (null === $reply || null === $case || ModerationCaseMessage::AUTHOR_STAFF !== $reply->getAuthorRole()) {
            return;
        }

        $memberId = $case->getTargetUserId();
        $discordId = $this->members->discordIdOf($memberId);

        if (null === $reply->getDiscordDmStatus()) {
            $reply->recordDirectMessage($this->sendDirectMessage($reply, $discordId));
            $this->messages->flush();
        }

        if (!$this->forum->isConfigured()) {
            return;
        }

        $names = $this->directory->namesFor([$memberId, $reply->getAuthorUserId()]);
        $memberName = $names[$memberId] ?? $memberId;
        $this->forum->deliver(
            $case,
            $memberName,
            $this->forumMessages->forStaffReply($reply, $memberName, $discordId, $names[$reply->getAuthorUserId()] ?? 'Un admin', $reply->getDiscordDmStatus()),
            ['messageId' => $reply->getId()],
        );
    }

    /**
     * @return string one of the {@see ModerationCaseMessage} DM outcomes
     */
    private function sendDirectMessage(ModerationCaseMessage $reply, ?string $discordId): string
    {
        if (null === $discordId) {
            return ModerationCaseMessage::DM_NOT_LINKED;
        }
        if (!$this->directMessages->isConfigured()) {
            return ModerationCaseMessage::DM_UNAVAILABLE;
        }

        try {
            $this->directMessages->send($discordId, $this->forumMessages->forMemberDirectMessage($reply));
        } catch (MemberDirectMessageException $e) {
            if ($e->transient) {
                throw new MemberDirectMessageTemporarilyUnavailableException($e->getMessage(), 0, $e);
            }
            $this->logger->warning('moderation_reply.dm_not_sent', [
                'messageId' => $reply->getId(),
                'error' => $e->getMessage(),
            ]);

            return ModerationCaseMessage::DM_FAILED;
        }

        return ModerationCaseMessage::DM_SENT;
    }
}
