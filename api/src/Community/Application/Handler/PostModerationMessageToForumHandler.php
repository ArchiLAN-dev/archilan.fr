<?php

declare(strict_types=1);

namespace App\Community\Application\Handler;

use App\Community\Application\Message\PostModerationMessageToForumJob;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Support\ModerationForumDelivery;
use App\Community\Application\Support\ModerationForumMessageFactory;
use App\Community\Domain\Repository\ModerationCaseMessageRepositoryInterface;
use App\Community\Domain\Repository\ModerationCaseRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Posts a member's message to the moderation in their case's staff forum post (story 39.2), opening the
 * post if the case has none yet. Delivery and its failures: {@see ModerationForumDelivery}.
 */
#[AsMessageHandler]
final readonly class PostModerationMessageToForumHandler
{
    public function __construct(
        private ModerationCaseMessageRepositoryInterface $messages,
        private ModerationCaseRepositoryInterface $cases,
        private MemberModerationGatewayInterface $members,
        private CommunityUserDirectoryQueryInterface $directory,
        private ModerationForumMessageFactory $forumMessages,
        private ModerationForumDelivery $delivery,
    ) {
    }

    public function __invoke(PostModerationMessageToForumJob $job): void
    {
        if (!$this->delivery->isConfigured()) {
            return;
        }

        $message = $this->messages->findById($job->messageId);
        $case = null !== $message ? $this->cases->findById($message->getCaseId()) : null;
        if (null === $message || null === $case) {
            return;
        }

        $memberId = $case->getTargetUserId();
        $memberName = $this->directory->namesFor([$memberId])[$memberId] ?? $memberId;

        $this->delivery->deliver(
            $case,
            $memberName,
            $this->forumMessages->forMemberMessage($message, $memberName, $this->members->discordIdOf($memberId)),
            ['messageId' => $message->getId()],
        );
    }
}
