<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Handler\SendWebPushJobHandler;
use App\Community\Application\Message\SendWebPushJob;
use App\Community\Application\Port\WebPushOutcome;
use App\Community\Application\Port\WebPushSender;
use App\Community\Application\Support\WebPushConfig;
use App\Community\Application\Support\WebPushMessage;
use App\Community\Domain\Entity\Notification;
use App\Community\Domain\Entity\PushSubscription;
use App\Community\Domain\Repository\NotificationRepositoryInterface;
use App\Community\Domain\Repository\PushSubscriptionRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Story 40.2. A pushable notification goes to every device of its recipient; a device the push
 * service says is gone is forgotten, and nothing is sent while the site has no VAPID keys.
 */
final class SendWebPushJobHandlerTest extends TestCase
{
    private InMemoryPushSubscriptions $subscriptions;
    private FakeWebPushSender $sender;

    protected function setUp(): void
    {
        $this->subscriptions = new InMemoryPushSubscriptions();
        $this->sender = new FakeWebPushSender();
    }

    public function testEveryDeviceOfTheRecipientGetsThePushAndAGoneOneIsForgotten(): void
    {
        $this->subscriptions->add($this->subscription('sub-1', 'user-1', 'https://push.example/phone'));
        $this->subscriptions->add($this->subscription('sub-2', 'user-1', 'https://push.example/gone'));
        $this->subscriptions->add($this->subscription('sub-3', 'user-2', 'https://push.example/other'));
        $this->sender->outcomes['https://push.example/gone'] = WebPushOutcome::Expired;

        $this->handler($this->unblocked(), configured: true)(new SendWebPushJob('notif-1'));

        self::assertSame(['https://push.example/phone', 'https://push.example/gone'], array_column($this->sender->sent, 'endpoint'));
        self::assertSame('/runs/run-1', $this->sender->sent[0]['message']->url);
        self::assertSame(['sub-1', 'sub-3'], array_keys($this->subscriptions->all));
    }

    public function testAFailedSendKeepsTheDevice(): void
    {
        $this->subscriptions->add($this->subscription('sub-1', 'user-1', 'https://push.example/phone'));
        $this->sender->outcomes['https://push.example/phone'] = WebPushOutcome::Failed;

        $this->handler($this->unblocked(), configured: true)(new SendWebPushJob('notif-1'));

        self::assertSame(['sub-1'], array_keys($this->subscriptions->all));
    }

    public function testNothingIsSentWithoutKeysForAnUnknownNotificationOrANonPushableType(): void
    {
        $this->subscriptions->add($this->subscription('sub-1', 'user-1', 'https://push.example/phone'));

        $this->handler($this->unblocked(), configured: false)(new SendWebPushJob('notif-1'));
        $this->handler(null, configured: true)(new SendWebPushJob('notif-1'));
        $friend = Notification::create('user-1', 'friend_request_received', [], new \DateTimeImmutable('2026-09-29T10:00:00+00:00'));
        $this->handler($friend, configured: true)(new SendWebPushJob('notif-1'));

        self::assertSame([], $this->sender->sent);
    }

    private function unblocked(): Notification
    {
        return Notification::create('user-1', 'slot_unblocked', [
            'runId' => 'run-1',
            'runTitle' => 'Ma run',
            'slotName' => 'Alice_HK1',
            'reachableNow' => 2,
        ], new \DateTimeImmutable('2026-09-29T10:00:00+00:00'));
    }

    private function subscription(string $id, string $userId, string $endpoint): PushSubscription
    {
        return PushSubscription::register($id, $userId, $endpoint, 'p256dh-key', 'auth-key', 'aes128gcm', null, new \DateTimeImmutable('2026-09-29T09:00:00+00:00'));
    }

    private function handler(?Notification $notification, bool $configured): SendWebPushJobHandler
    {
        $notifications = self::createStub(NotificationRepositoryInterface::class);
        $notifications->method('findById')->willReturn($notification);

        $config = $configured
            ? new WebPushConfig('public-key', 'private-key', 'mailto:contact@archilan.fr')
            : new WebPushConfig('', '', '');

        return new SendWebPushJobHandler($notifications, $this->subscriptions, $this->sender, $config, new NullLogger());
    }
}

final class InMemoryPushSubscriptions implements PushSubscriptionRepositoryInterface
{
    /** @var array<string, PushSubscription> */
    public array $all = [];

    public function findByEndpoint(string $endpoint): ?PushSubscription
    {
        foreach ($this->all as $subscription) {
            if ($subscription->getEndpoint() === $endpoint) {
                return $subscription;
            }
        }

        return null;
    }

    public function findByUserId(string $userId): array
    {
        return array_values(array_filter($this->all, static fn (PushSubscription $subscription): bool => $subscription->getUserId() === $userId));
    }

    public function add(PushSubscription $subscription): void
    {
        $this->all[$subscription->getId()] = $subscription;
    }

    public function remove(PushSubscription $subscription): void
    {
        unset($this->all[$subscription->getId()]);
    }

    public function flush(): void
    {
    }
}

final class FakeWebPushSender implements WebPushSender
{
    /** @var array<string, WebPushOutcome> */
    public array $outcomes = [];

    /** @var list<array{endpoint: string, message: WebPushMessage}> */
    public array $sent = [];

    public function send(PushSubscription $subscription, WebPushMessage $message): WebPushOutcome
    {
        $this->sent[] = ['endpoint' => $subscription->getEndpoint(), 'message' => $message];

        return $this->outcomes[$subscription->getEndpoint()] ?? WebPushOutcome::Delivered;
    }
}
