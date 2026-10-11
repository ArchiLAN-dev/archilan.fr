<?php

declare(strict_types=1);

namespace App\Community\Application\Handler;

use App\Community\Application\Exception\MemberDirectMessageException;
use App\Community\Application\Exception\MemberDirectMessageTemporarilyUnavailableException;
use App\Community\Application\Message\SendDiscordNotificationJob;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Port\NotificationDirectMessageInterface;
use App\Community\Application\Support\PushMessageFactory;
use App\Community\Domain\Repository\NotificationRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Story 40.6: sends a notification to its recipient's Discord direct messages, with the push's text. Nothing goes out
 * to a member whose Discord is not linked. A transient failure is retried by Messenger; a refusal (closed DMs, member
 * gone from the server) is logged and left there - the notification is already in the bell.
 */
#[AsMessageHandler]
final readonly class SendDiscordNotificationJobHandler
{
    public function __construct(
        private NotificationRepositoryInterface $notifications,
        private MemberModerationGatewayInterface $members,
        private NotificationDirectMessageInterface $directMessages,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendDiscordNotificationJob $job): void
    {
        if (!$this->directMessages->isConfigured()) {
            return;
        }

        $notification = $this->notifications->findById($job->notificationId);
        if (null === $notification) {
            return;
        }
        $message = PushMessageFactory::forNotification($notification->getType(), $notification->getPayload());
        $discordId = $this->members->discordIdOf($notification->getRecipientId());
        if (null === $message || null === $discordId) {
            return;
        }

        try {
            $this->directMessages->send($discordId, $message);
        } catch (MemberDirectMessageException $e) {
            if ($e->transient) {
                throw new MemberDirectMessageTemporarilyUnavailableException($e->getMessage(), 0, $e);
            }
            $this->logger->warning('community.discord_notification_refused', [
                'notificationId' => $job->notificationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
