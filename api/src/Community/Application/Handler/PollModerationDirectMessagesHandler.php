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
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Reads, every minute, what members answered the bot in private (story 39.4): the bot holds no live
 * connection to Discord, so its DM channels are read back, for the open cases only. Each answer joins the case
 * and goes to the staff forum; the bot's own messages move the cursor and nothing else.
 *
 * A case that fails (Discord down, rate limit) is logged and left for the next minute, from the same cursor,
 * and never stops the others.
 *
 * Story 39.9: cases closed within the last 30 days are still read, the staff may answer after a lift; and at
 * most five answers an hour per case go to the forum, the others staying in the case for the admin sheet.
 */
#[AsMessageHandler]
final readonly class PollModerationDirectMessagesHandler
{
    public const int FORWARDED_PER_HOUR = 5;
    private const string CLOSED_CASES_READ_FOR = 'P30D';

    public function __construct(
        private ModerationCaseRepositoryInterface $cases,
        private ModerationCaseMessageRepositoryInterface $messages,
        private MemberModerationGatewayInterface $members,
        private MemberDirectMessageInterface $directMessages,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PollModerationDirectMessagesMessage $message): void
    {
        if (!$this->directMessages->isConfigured()) {
            return;
        }

        $closedSince = $this->clock->now()->sub(new \DateInterval(self::CLOSED_CASES_READ_FOR));
        foreach ($this->cases->withDirectMessagesToRead($closedSince) as $case) {
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

        $forwardable = max(0, self::FORWARDED_PER_HOUR - $this->messages->countFromMemberSince(
            $case->getId(),
            $this->clock->now()->sub(new \DateInterval('PT1H')),
            ModerationCaseMessage::SOURCE_DISCORD_DM,
        ));
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

        foreach (array_slice($imported, 0, $forwardable) as $messageId) {
            $this->bus->dispatch(new PostModerationMessageToForumJob($messageId));
        }
        if (\count($imported) > $forwardable) {
            $this->logger->info('moderation_dm.forward_capped', ['caseId' => $case->getId(), 'kept' => \count($imported) - $forwardable]);
        }
    }
}
