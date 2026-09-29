<?php

declare(strict_types=1);

namespace App\Community\Application\Handler;

use App\Community\Application\Message\SendWebPushJob;
use App\Community\Application\Port\WebPushOutcome;
use App\Community\Application\Port\WebPushSender;
use App\Community\Application\Support\PushMessageFactory;
use App\Community\Application\Support\WebPushConfig;
use App\Community\Domain\Repository\NotificationRepositoryInterface;
use App\Community\Domain\Repository\PushSubscriptionRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Story 40.2: pushes a notification to every device its recipient subscribed. A device its push service
 * no longer knows is forgotten; any other failure is logged and never retried - the notification is
 * already in the bell, the push is only a courtesy.
 */
#[AsMessageHandler]
final readonly class SendWebPushJobHandler
{
    public function __construct(
        private NotificationRepositoryInterface $notifications,
        private PushSubscriptionRepositoryInterface $subscriptions,
        private WebPushSender $sender,
        private WebPushConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendWebPushJob $job): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }

        $notification = $this->notifications->findById($job->notificationId);
        if (null === $notification) {
            return;
        }

        $message = PushMessageFactory::forNotification($notification->getType(), $notification->getPayload());
        if (null === $message) {
            return;
        }

        $forgotten = false;
        foreach ($this->subscriptions->findByUserId($notification->getRecipientId()) as $subscription) {
            try {
                $outcome = $this->sender->send($subscription, $message);
            } catch (\Throwable $e) {
                $outcome = WebPushOutcome::Failed;
                $this->logger->warning('community.web_push_error', ['subscriptionId' => $subscription->getId(), 'error' => $e->getMessage()]);
            }

            if (WebPushOutcome::Expired === $outcome) {
                $this->subscriptions->remove($subscription);
                $forgotten = true;
            } elseif (WebPushOutcome::Failed === $outcome) {
                $this->logger->warning('community.web_push_failed', [
                    'subscriptionId' => $subscription->getId(),
                    'notificationId' => $job->notificationId,
                ]);
            }
        }

        if ($forgotten) {
            $this->subscriptions->flush();
        }
    }
}
