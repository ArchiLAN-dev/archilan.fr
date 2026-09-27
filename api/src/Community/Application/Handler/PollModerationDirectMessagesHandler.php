<?php

declare(strict_types=1);

namespace App\Community\Application\Handler;

use App\Community\Application\Message\PollModerationDirectMessagesMessage;
use App\Community\Application\Message\PostModerationMessageToForumJob;
use App\Community\Application\Port\MemberDirectMessageInterface;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Domain\Entity\ModerationCase;
use App\Community\Domain\Entity\ModerationCaseMessage;
use App\Community\Domain\Repository\ModerationCaseMessageRepositoryInterface;
use App\Community\Domain\Repository\ModerationCaseRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Reads, every minute, what members answered the bot in private (story 39.4): the bot holds no live
 * connection to Discord, so its DM channels are read back, for the open cases only. Each answer joins the case
 * and goes to the staff forum; the bot's own messages move the cursor and nothing else.
 *
 * Runs on the scheduler, which never retries: a case that fails (Discord down, rate limit) is logged and left
 * for the next minute, from the same cursor, and never stops the others.
 */
#[AsMessageHandler]
final readonly class PollModerationDirectMessagesHandler
{
    public function __construct(
        private ModerationCaseRepositoryInterface $cases,
        private ModerationCaseMessageRepositoryInterface $messages,
        private MemberModerationGatewayInterface $members,
        private MemberDirectMessageInterface $directMessages,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PollModerationDirectMessagesMessage $message): void
    {
        if (!$this->directMessages->isConfigured()) {
            return;
        }

        foreach ($this->cases->openWithDirectMessageChannel() as $case) {
            try {
                $this->read($case);
            } catch (\Throwable $e) {
                $this->logger->warning('moderation_dm.poll_failed', [
                    'caseId' => $case->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function read(ModerationCase $case): void
    {
        $channelId = $case->getDirectMessageChannelId();
        $cursor = $case->getDirectMessageCursor();
        $memberId = $case->getTargetUserId();
        $discordId = $this->members->discordIdOf($memberId);
        if (null === $channelId || null === $cursor || null === $discordId) {
            return;
        }

        $imported = [];
        foreach ($this->directMessages->messagesAfter($channelId, $cursor) as $incoming) {
            $case->advanceDirectMessageCursor($incoming->id);
            if ($incoming->authorId !== $discordId || '' === trim($incoming->content)
                || null !== $this->messages->findByDiscordMessageId($incoming->id)) {
                continue;
            }

            $answer = ModerationCaseMessage::fromMemberDirectMessage($case->getId(), $memberId, $incoming->content, new \DateTimeImmutable($incoming->sentAt), $incoming->id);
            $this->messages->save($answer);
            $imported[] = $answer->getId();
        }
        $this->messages->flush();

        foreach ($imported as $messageId) {
            $this->bus->dispatch(new PostModerationMessageToForumJob($messageId));
        }
    }
}
