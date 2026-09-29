<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\Message\SendWebPushJob;
use App\Community\Application\Support\Notifier;
use App\Community\Domain\Entity\PushSubscription;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 40.2. A member registers the device they are on, can remove it, and never touches anyone
 * else's; a pushable notification queues its browser push.
 */
final class PushSubscriptionControllerTest extends FunctionalTestCase
{
    private const string ENDPOINT = 'https://fcm.googleapis.com/fcm/send/device-1';

    public function testThePublicKeyIsServedToEveryone(): void
    {
        $this->client->request('GET', '/api/v1/push/public-key');

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertIsString($data['publicKey']);
        self::assertNotSame('', $data['publicKey']);
    }

    public function testRegisteringNeedsAnAccount(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/account/push-subscriptions', $this->body());

        self::assertResponseStatusCodeSame(401);
    }

    public function testAMemberRegistersTheirDeviceOnceAndRemovesIt(): void
    {
        $user = $this->createUser('push@example.org', ['ROLE_USER'], 'Alice');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/account/push-subscriptions', $this->body());
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/api/v1/account/push-subscriptions', $this->body());
        self::assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(PushSubscription::class)->findBy(['endpoint' => self::ENDPOINT]);
        self::assertCount(1, $rows, 'the same device registers once');
        self::assertSame($user->getId(), $rows[0]->getUserId());

        $this->client->jsonRequest('DELETE', '/api/v1/account/push-subscriptions', ['endpoint' => self::ENDPOINT]);
        self::assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        self::assertSame([], $this->entityManager->getRepository(PushSubscription::class)->findBy(['endpoint' => self::ENDPOINT]));
    }

    public function testAMalformedSubscriptionIsRefused(): void
    {
        $this->loginAs($this->createUser('push-bad@example.org', ['ROLE_USER'], 'Alice'));

        $this->client->jsonRequest('POST', '/api/v1/account/push-subscriptions', ['endpoint' => 'http://insecure.example/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']]);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', '/api/v1/account/push-subscriptions', ['endpoint' => self::ENDPOINT]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testNobodyRemovesSomeoneElsesDevice(): void
    {
        $owner = $this->createUser('push-owner@example.org', ['ROLE_USER'], 'Alice');
        $other = $this->createUser('push-other@example.org', ['ROLE_USER'], 'Bob');
        $this->loginAs($owner);
        $this->client->jsonRequest('POST', '/api/v1/account/push-subscriptions', $this->body());
        self::assertResponseStatusCodeSame(201);

        $this->loginAs($other);
        $this->client->jsonRequest('DELETE', '/api/v1/account/push-subscriptions', ['endpoint' => self::ENDPOINT]);
        self::assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        self::assertCount(1, $this->entityManager->getRepository(PushSubscription::class)->findBy(['endpoint' => self::ENDPOINT]));
    }

    public function testAPushableNotificationQueuesItsBrowserPush(): void
    {
        $user = $this->createUser('push-notif@example.org', ['ROLE_USER'], 'Alice');
        $notifier = self::getContainer()->get(Notifier::class);
        self::assertInstanceOf(Notifier::class, $notifier);

        $notifier->notify($user->getId(), 'friend_request_received', ['fromUserId' => 'someone']);
        $notifier->notify($user->getId(), 'slot_unblocked', ['runId' => 'run-1', 'runTitle' => 'Ma run', 'slotName' => 'Alice_HK1', 'reachableNow' => 2]);

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $jobs = array_values(array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof SendWebPushJob,
        ));
        self::assertCount(1, $jobs, 'only the pushable type is pushed');
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        return [
            'endpoint' => self::ENDPOINT,
            'keys' => [
                'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
                'auth' => 'tBHItJI5svbpez7KI4CCXg',
            ],
            'contentEncoding' => 'aes128gcm',
        ];
    }
}
