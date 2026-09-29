<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Adapter;

use App\Community\Application\Port\WebPushOutcome;
use App\Community\Application\Port\WebPushSender;
use App\Community\Application\Support\WebPushConfig;
use App\Community\Application\Support\WebPushMessage;
use App\Community\Domain\Entity\PushSubscription;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Browser pushes through minishlink/web-push (story 40.2): it encrypts the payload for the device's keys
 * (RFC 8291) and signs the request with the site's VAPID keys (RFC 8292). The HTTP call goes through the
 * app's Symfony client, like every other outgoing call.
 */
final readonly class MinishlinkWebPushSender implements WebPushSender
{
    /** A push that cannot be delivered within the hour is no longer worth showing. */
    private const int TTL_SECONDS = 3600;

    public function __construct(
        private WebPushConfig $config,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    public function send(PushSubscription $subscription, WebPushMessage $message): WebPushOutcome
    {
        $webPush = new WebPush(
            ['VAPID' => $this->config->vapid()],
            ['TTL' => self::TTL_SECONDS, 'urgency' => 'high', 'topic' => substr(hash('sha256', $message->tag), 0, 32)],
            new Psr18Client($this->httpClient),
        );

        $report = $webPush->sendOneNotification(
            Subscription::create([
                'endpoint' => $subscription->getEndpoint(),
                'keys' => ['p256dh' => $subscription->getP256dh(), 'auth' => $subscription->getAuth()],
                'contentEncoding' => $subscription->getContentEncoding(),
            ]),
            $message->toJson(),
        );

        $outcome = self::outcomeOf($report);
        if (WebPushOutcome::Failed === $outcome) {
            $this->logger->warning('community.web_push_rejected', [
                'subscriptionId' => $subscription->getId(),
                'reason' => $report->getReason(),
            ]);
        }

        return $outcome;
    }

    public static function outcomeOf(MessageSentReport $report): WebPushOutcome
    {
        if ($report->isSuccess()) {
            return WebPushOutcome::Delivered;
        }

        return $report->isSubscriptionExpired() ? WebPushOutcome::Expired : WebPushOutcome::Failed;
    }
}
