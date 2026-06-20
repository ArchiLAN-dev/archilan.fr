<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;

final class CommunityFeedTest extends FunctionalTestCase
{
    public function testFriendsFeedShowsAFriendsActivity(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $this->becomeFriends($alice, $bob);

        // Bob (the accepter) produced a 'friendship' entry; Alice (his friend) sees it in her feed.
        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/community/feed');
        self::assertResponseIsSuccessful();
        $items = $this->items();
        self::assertNotEmpty($items);

        $first = $items[0];
        self::assertIsArray($first);
        self::assertSame('friendship', $first['type']);
        self::assertIsArray($first['actor']);
        self::assertSame('bob', $first['actor']['slug']);
        self::assertSame('alice', $first['withSlug']);
    }

    public function testProfileActivityRespectsAudienceAndAuth(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $carol = $this->createUser('carol@example.org', slug: 'carol');
        $this->becomeFriends($alice, $bob);

        // Bob restricts his profile to friends.
        $this->loginAs($bob);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['audience' => 'friends']);
        self::assertResponseIsSuccessful();

        // Carol (not a friend) cannot see Bob's activity.
        $this->loginAs($carol);
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/bob/activity');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->items());

        // Alice (a friend) can.
        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/bob/activity');
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($this->items());
    }

    public function testFeedRequiresAuthentication(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/community/feed');
        self::assertResponseStatusCodeSame(401);
    }

    private function becomeFriends(User $a, User $b): void
    {
        $this->loginAs($a);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/'.$b->getSlug().'/friend-request');
        self::assertResponseIsSuccessful();

        $this->loginAs($b);
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
    }

    /**
     * @return list<mixed>
     */
    private function items(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return array_values($data);
    }
}
