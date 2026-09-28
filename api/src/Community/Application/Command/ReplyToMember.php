<?php

declare(strict_types=1);

namespace App\Community\Application\Command;

use App\Community\Application\Message\DeliverStaffReplyJob;
use App\Community\Application\Support\Notifier;
use App\Community\Domain\Entity\ModerationCase;
use App\Community\Domain\Entity\ModerationCaseMessage;
use App\Community\Domain\Entity\Notification;
use App\Community\Domain\Repository\ModerationActionRepositoryInterface;
use App\Community\Domain\Repository\ModerationCaseMessageRepositoryInterface;
use App\Community\Domain\Repository\ModerationCaseRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The staff answers a sanctioned member from the admin page (story 39.3). Replies leave from the site only:
 * what the staff writes in the forum post stays between them. After the commit, the member is notified on the
 * site, and the reply goes out by the bot's direct message and to the forum.
 */
final readonly class ReplyToMember
{
    private const int HISTORY_SCAN = 200;

    public function __construct(
        private ModerationCaseRepositoryInterface $cases,
        private ModerationCaseMessageRepositoryInterface $messages,
        private ModerationActionRepositoryInterface $actions,
        private Notifier $notifier,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function reply(string $staffId, string $memberId, string $body): ReplyToMemberOutcome
    {
        $now = $this->clock->now();
        $case = $this->cases->findByTargetUserId($memberId);
        if (null === $case) {
            // Same rule as the member's side: a case belongs to a sanctioned member.
            if (!$this->hasSanction($memberId)) {
                return ReplyToMemberOutcome::NotSanctioned;
            }
            $case = ModerationCase::open($memberId, $now);
        }

        try {
            $reply = ModerationCaseMessage::fromStaff($case->getId(), $staffId, $body, $now);
        } catch (\InvalidArgumentException) {
            return ReplyToMemberOutcome::Invalid;
        }

        $this->cases->save($case);
        $this->messages->save($reply);
        $this->messages->flush();

        $this->notifier->notify($memberId, Notification::TYPE_MODERATION_REPLY, []);
        try {
            $this->bus->dispatch(new DeliverStaffReplyJob($reply->getId()));
        } catch (\Throwable $e) {
            // The member still reads it on the site.
            $this->logger->error('moderation_reply.dispatch_failed', [
                'messageId' => $reply->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return ReplyToMemberOutcome::Sent;
    }

    /** Story 39.10: a note is for the staff only, it is nothing the member could talk about. */
    private function hasSanction(string $memberId): bool
    {
        return array_any($this->actions->forTarget($memberId, self::HISTORY_SCAN), fn ($action) => $action->isSanction());
    }
}
