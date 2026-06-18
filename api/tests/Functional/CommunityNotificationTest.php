<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\AchievementGrant;

final class CommunityNotificationTest extends FunctionalTestCase
{
    public function testFriendRequestAndAcceptNotificationsLifecycle(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        // Alice requests Bob -> Bob gets a "request received" notification from Alice.
        $this->loginAs($alice);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/bob/friend-request');
        self::assertResponseIsSuccessful();

        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/community/notifications');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->meta()['unreadCount']);
        $received = $this->data();
        self::assertCount(1, $received);
        $first = $received[0];
        self::assertIsArray($first);
        self::assertSame('friend_request_received', $first['type']);
        self::assertFalse($first['read']);
        self::assertIsArray($first['actor']);
        self::assertSame('alice', $first['actor']['slug']);

        // Bob accepts -> Alice gets a "request accepted" notification from Bob.
        $friendshipId = $this->acceptIncoming();
        self::assertNotSame('', $friendshipId);

        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/community/notifications');
        self::assertSame(1, $this->meta()['unreadCount']);
        $accepted = $this->data();
        $acceptedFirst = $accepted[0];
        self::assertIsArray($acceptedFirst);
        self::assertSame('friend_request_accepted', $acceptedFirst['type']);
        self::assertIsArray($acceptedFirst['actor']);
        self::assertSame('bob', $acceptedFirst['actor']['slug']);

        // Mark the single notification read.
        $id = $acceptedFirst['id'];
        self::assertIsString($id);
        $this->client->jsonRequest('POST', '/api/v1/community/notifications/'.$id.'/read');
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('GET', '/api/v1/community/notifications');
        self::assertSame(0, $this->meta()['unreadCount']);
        $afterRead = $this->data();
        $afterReadFirst = $afterRead[0];
        self::assertIsArray($afterReadFirst);
        self::assertTrue($afterReadFirst['read']);
    }

    public function testKudosNotifiesTheOwnerAndReadAll(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $grant = AchievementGrant::grant($alice->getId(), 'first_run', new \DateTimeImmutable());
        $this->entityManager->persist($grant);
        $this->entityManager->flush();

        $this->loginAs($bob);
        $this->client->jsonRequest('POST', '/api/v1/community/kudos', ['targetType' => 'achievement', 'targetId' => $grant->getId()]);
        self::assertResponseIsSuccessful();

        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/community/notifications');
        self::assertSame(1, $this->meta()['unreadCount']);
        $items = $this->data();
        $first = $items[0];
        self::assertIsArray($first);
        self::assertSame('kudos_received', $first['type']);
        self::assertIsArray($first['actor']);
        self::assertSame('bob', $first['actor']['slug']);
        self::assertIsArray($first['data']);
        self::assertSame('achievement', $first['data']['targetType']);

        // Mark all read.
        $this->client->jsonRequest('POST', '/api/v1/community/notifications/read-all');
        self::assertResponseStatusCodeSame(204);
        $this->client->jsonRequest('GET', '/api/v1/community/notifications');
        self::assertSame(0, $this->meta()['unreadCount']);
    }

    public function testCannotReadAnotherUsersNotification(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        $this->loginAs($alice);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/bob/friend-request');
        self::assertResponseIsSuccessful();

        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/community/notifications');
        $bobNotification = $this->data()[0];
        self::assertIsArray($bobNotification);
        $id = $bobNotification['id'];
        self::assertIsString($id);

        // Alice cannot mark Bob's notification read.
        $this->loginAs($alice);
        $this->client->jsonRequest('POST', '/api/v1/community/notifications/'.$id.'/read');
        self::assertResponseStatusCodeSame(403);

        // A missing id is 404.
        $this->client->jsonRequest('POST', '/api/v1/community/notifications/deadbeef/read');
        self::assertResponseStatusCodeSame(404);
    }

    public function testNotificationsRequireAuthentication(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/community/notifications');
        self::assertResponseStatusCodeSame(401);
    }

    public function testNotificationsTokenScopedToOwnTopic(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/realtime/notifications-token');
        self::assertResponseStatusCodeSame(401);

        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/realtime/notifications-token');
        self::assertResponseStatusCodeSame(200);

        $data = $this->data();
        self::assertIsString($data['token']);
        self::assertIsString($data['hubUrl']);
        self::assertSame('https://archilan.fr/users/'.$alice->getId().'/notifications', $data['topic']);
    }

    private function acceptIncoming(): string
    {
        $this->client->jsonRequest('GET', '/api/v1/community/friends');
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $incoming = $data['incoming'] ?? null;
        self::assertIsArray($incoming);
        $first = $incoming[0] ?? null;
        self::assertIsArray($first);
        $friendshipId = $first['friendshipId'] ?? null;
        self::assertIsString($friendshipId);
        $this->client->jsonRequest('POST', '/api/v1/community/friendships/'.$friendshipId.'/accept');
        self::assertResponseStatusCodeSame(204);

        return $friendshipId;
    }

    /**
     * @return array<mixed>
     */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return array<mixed>
     */
    private function meta(): array
    {
        $meta = $this->decodedJsonResponse()['meta'] ?? null;
        self::assertIsArray($meta);

        return $meta;
    }
}
