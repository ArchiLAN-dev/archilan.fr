<?php

declare(strict_types=1);

namespace App\Community\Application\Command;

use App\Community\Application\Message\PostModerationMessageToForumJob;
use App\Community\Domain\Entity\ModerationCase;
use App\Community\Domain\Entity\ModerationCaseMessage;
use App\Community\Domain\Repository\ModerationActionRepositoryInterface;
use App\Community\Domain\Repository\ModerationCaseMessageRepositoryInterface;
use App\Community\Domain\Repository\ModerationCaseRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A sanctioned member writes to the moderation (story 39.2), from their account or with the contact pass of
 * a blocked login. The message joins the member's case, opened here if their sanctions predate the cases,
 * then goes to the staff forum after the commit.
 */
final readonly class ContactModeration
{
    public const int MAX_PER_HOUR = 5;

    public function __construct(
        private ModerationCaseRepositoryInterface $cases,
        private ModerationCaseMessageRepositoryInterface $messages,
        private ModerationActionRepositoryInterface $actions,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function write(string $memberId, string $body): ContactModerationOutcome
    {
        $now = $this->clock->now();
        $case = $this->cases->findByTargetUserId($memberId);
        if (null === $case) {
            // Nothing to contest without a sanction: the route is not a general contact form.
            if ([] === $this->actions->forTarget($memberId, 1)) {
                return ContactModerationOutcome::NotSanctioned;
            }
            $case = ModerationCase::open($memberId, $now);
        } elseif ($this->messages->countFromMemberSince($case->getId(), $now->sub(new \DateInterval('PT1H'))) >= self::MAX_PER_HOUR) {
            return ContactModerationOutcome::TooMany;
        }

        try {
            $message = ModerationCaseMessage::fromMember($case->getId(), $memberId, $body, $now);
        } catch (\InvalidArgumentException) {
            return ContactModerationOutcome::Invalid;
        }

        $this->cases->save($case);
        $this->messages->save($message);
        $this->messages->flush();

        try {
            $this->bus->dispatch(new PostModerationMessageToForumJob($message->getId()));
        } catch (\Throwable $e) {
            // The staff still reads it in the case on the site.
            $this->logger->error('moderation_forum.dispatch_failed', [
                'messageId' => $message->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return ContactModerationOutcome::Sent;
    }
}
