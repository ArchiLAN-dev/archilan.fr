<?php

declare(strict_types=1);

namespace App\Community\Application\Handler;

use App\Community\Application\Message\DeliverStaffReplyJob;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Support\MemberDirectMessenger;
use App\Community\Application\Support\ModerationForumDelivery;
use App\Community\Application\Support\ModerationForumMessageFactory;
use App\Community\Domain\Entity\ModerationCaseMessage;
use App\Community\Domain\Repository\ModerationCaseMessageRepositoryInterface;
use App\Community\Domain\Repository\ModerationCaseRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Brings a staff reply to the member, by the bot's direct message, then to the staff forum with the outcome of
 * that message (story 39.3).
 *
 * The outcome is committed before the forum is tried, so a retry of the job (forum down) never sends the
 * member the same message twice. Sending and its failures: {@see MemberDirectMessenger}.
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
        private MemberDirectMessenger $directMessages,
        private ModerationForumDelivery $forum,
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
            $reply->recordDirectMessage($this->directMessages->send(
                $case,
                $discordId,
                $this->forumMessages->forMemberDirectMessage($reply),
                ['messageId' => $reply->getId()],
            ));
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
}
