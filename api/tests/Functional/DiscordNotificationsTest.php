<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\Exception\MemberDirectMessageTemporarilyUnavailableException;
use App\Community\Application\Handler\SendDiscordNotificationJobHandler;
use App\Community\Application\Message\SendDiscordNotificationJob;
use App\Community\Application\Port\NotificationDirectMessageInterface;
use App\Community\Application\Support\Notifier;
use App\Community\Application\Support\WebPushMessage;
use App\Community\Domain\Entity\Notification;
use App\Community\Infrastructure\Adapter\DiscordNotificationDirectMessages;
use App\Identity\Domain\Entity\User;
use App\Tests\Fixtures\Community\SpyNotificationDirectMessages;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 40.6: the notifications a member keeps on Discord go to their direct messages, from the bot.
 */
final class DiscordNotificationsTest extends FunctionalTestCase
{
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member', 'member');
    }

    public function testThePreferencesOfferDiscordOnlyOnceItIsLinked(): void
    {
        $this->loginAs($this->member);
        $this->client->request('GET', '/api/v1/community/notification-preferences');
        self::assertResponseIsSuccessful();
        foreach ($this->rows() as $row) {
            self::assertArrayHasKey('discord', $row);
            self::assertNull($row['discord'], 'nothing to choose without Discord');
        }

        $this->link($this->member);
        $this->client->request('GET', '/api/v1/community/notification-preferences');
        $discord = array_column($this->rows(), 'discord', 'type');
        self::assertFalse($discord['friend_activity'], 'off by default');
        self::assertTrue($discord['run_started'], 'the moments of a run are on by default');
        self::assertTrue($discord['run_invitation']);
    }

    public function testTheMemberTurnsDiscordOffForOneType(): void
    {
        $this->link($this->member);
        $this->loginAs($this->member);

        $this->client->jsonRequest('PUT', '/api/v1/community/notification-preferences/run_started', ['discord' => false]);
        self::assertResponseIsSuccessful();
        $rows = array_column($this->rows(), null, 'type');
        self::assertFalse($rows['run_started']['discord']);
        self::assertSame('bell_push', $rows['run_started']['channel'], 'the bell and push choice is untouched');

        $this->client->jsonRequest('PUT', '/api/v1/community/notification-preferences/comment_received', ['discord' => true]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testANotificationKeptOnDiscordQueuesADirectMessage(): void
    {
        $this->link($this->member);
        $this->client->disableReboot();

        $this->notifier()->notify($this->member->getId(), 'run_started', ['runId' => 'r1', 'runTitle' => 'Ma run']);
        self::assertCount(1, $this->queued());

        $this->notifier()->notify($this->member->getId(), 'friend_activity', ['kind' => 'registered']);
        self::assertCount(1, $this->queued(), 'a type off by default sends nothing');
    }

    public function testATypeTurnedOffSendsNothing(): void
    {
        $this->link($this->member);
        $this->loginAs($this->member);
        $this->client->disableReboot();
        $this->client->jsonRequest('PUT', '/api/v1/community/notification-preferences/run_started', ['discord' => false]);
        self::assertResponseIsSuccessful();

        $this->notifier()->notify($this->member->getId(), 'run_started', ['runId' => 'r1', 'runTitle' => 'Ma run']);

        self::assertSame([], $this->queued());
    }

    public function testTheBotSendsThePushTextToTheLinkedAccount(): void
    {
        $this->link($this->member);
        $spy = $this->spy();
        $notification = $this->notification('run_started', ['runId' => 'r1', 'runTitle' => 'Ma run']);

        $this->handler()(new SendDiscordNotificationJob($notification->getId()));

        self::assertCount(1, $spy->sent);
        self::assertSame('123456789', $spy->sent[0]['discordUserId']);
        self::assertSame('« Ma run » est lancée : à toi de jouer', $spy->sent[0]['message']->body);
        self::assertSame('/runs/r1', $spy->sent[0]['message']->url);
    }

    public function testNothingGoesOutWithoutDiscordOrWhileSwitchedOff(): void
    {
        $spy = $this->spy();
        $notification = $this->notification('run_started', ['runId' => 'r1']);
        $this->handler()(new SendDiscordNotificationJob($notification->getId()));
        self::assertSame([], $spy->sent, 'Discord not linked');

        $this->link($this->member);
        $spy->configured = false;
        $this->handler()(new SendDiscordNotificationJob($notification->getId()));
        self::assertSame([], $spy->sent, 'switched off');
    }

    public function testARefusalIsLeftThereAndATransientFailureRetried(): void
    {
        $this->link($this->member);
        $spy = $this->spy();
        $notification = $this->notification('run_started', ['runId' => 'r1']);

        $spy->failTransient = false;
        $this->handler()(new SendDiscordNotificationJob($notification->getId()));

        $spy->failTransient = true;
        $this->expectException(MemberDirectMessageTemporarilyUnavailableException::class);
        $this->handler()(new SendDiscordNotificationJob($notification->getId()));
    }

    public function testTheMessageLinksToTheSiteAndMentionsNobody(): void
    {
        $body = DiscordNotificationDirectMessages::body(new WebPushMessage('ArchiLAN', '@everyone a rejoint', '/runs/r1', 't'), 'https://archilan.fr/');

        self::assertSame(['parse' => []], $body['allowed_mentions']);
        $embeds = $body['embeds'];
        self::assertIsArray($embeds);
        self::assertIsArray($embeds[0]);
        self::assertSame('https://archilan.fr/runs/r1', $embeds[0]['url']);
        self::assertSame('@everyone a rejoint', $embeds[0]['title']);
    }

    private function link(User $user): void
    {
        $user->linkDiscord('123456789', 'member#0001', new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function notification(string $type, array $payload): Notification
    {
        $notification = Notification::create($this->member->getId(), $type, $payload, new \DateTimeImmutable());
        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $notification;
    }

    /** @return list<array<mixed>> */
    private function rows(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return array_values(array_filter($data, is_array(...)));
    }

    /** @return list<SendDiscordNotificationJob> */
    private function queued(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof SendDiscordNotificationJob,
        ));
    }

    private function spy(): SpyNotificationDirectMessages
    {
        $spy = new SpyNotificationDirectMessages();
        self::getContainer()->set(NotificationDirectMessageInterface::class, $spy);

        return $spy;
    }

    private function notifier(): Notifier
    {
        $notifier = self::getContainer()->get(Notifier::class);
        self::assertInstanceOf(Notifier::class, $notifier);

        return $notifier;
    }

    private function handler(): SendDiscordNotificationJobHandler
    {
        $handler = self::getContainer()->get(SendDiscordNotificationJobHandler::class);
        self::assertInstanceOf(SendDiscordNotificationJobHandler::class, $handler);

        return $handler;
    }
}
