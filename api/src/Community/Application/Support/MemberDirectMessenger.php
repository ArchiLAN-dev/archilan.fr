<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Application\Exception\MemberDirectMessageException;
use App\Community\Application\Exception\MemberDirectMessageTemporarilyUnavailableException;
use App\Community\Application\Port\MemberDirectMessageInterface;
use App\Community\Domain\Entity\ModerationCase;
use App\Community\Domain\Entity\ModerationCaseMessage;
use Psr\Log\LoggerInterface;

/**
 * Sends a member the bot's direct message about their case (stories 39.3 and 39.4) and says how it went, as
 * one of the {@see ModerationCaseMessage} DM outcomes. A message that got through gives the case its DM
 * channel, read afterwards for the member's answers.
 *
 * A passing failure goes back to Messenger with nothing recorded, so the job sends it on its retry; a refusal
 * (closed DMs, member gone from the server) is logged and reported, never retried.
 */
final readonly class MemberDirectMessenger
{
    public function __construct(
        private MemberDirectMessageInterface $directMessages,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $logContext what the log line names if Discord refuses the message
     *
     * @throws MemberDirectMessageTemporarilyUnavailableException
     */
    public function send(ModerationCase $case, ?string $discordId, ModerationForumMessage $message, array $logContext): string
    {
        if (null === $discordId) {
            return ModerationCaseMessage::DM_NOT_LINKED;
        }
        if (!$this->directMessages->isConfigured()) {
            return ModerationCaseMessage::DM_UNAVAILABLE;
        }

        try {
            $sent = $this->directMessages->send($discordId, $message);
        } catch (MemberDirectMessageException $e) {
            if ($e->transient) {
                throw new MemberDirectMessageTemporarilyUnavailableException($e->getMessage(), 0, $e);
            }
            $this->logger->warning('moderation_dm.not_sent', [
                ...$logContext,
                'targetUserId' => $case->getTargetUserId(),
                'error' => $e->getMessage(),
            ]);

            return ModerationCaseMessage::DM_FAILED;
        }

        $case->attachDirectMessageChannel($sent->channelId, $sent->messageId);

        return ModerationCaseMessage::DM_SENT;
    }
}
