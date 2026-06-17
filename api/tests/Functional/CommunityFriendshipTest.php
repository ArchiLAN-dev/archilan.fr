<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;

final class CommunityFriendshipTest extends FunctionalTestCase
{
    public function testRequestAndAcceptMakesFriendsAndUnlocksFriendAudience(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $this->createUser('carol@example.org', slug: 'carol');

        // Alice restricts her customization to friends + writes a bio.
        $this->loginAs($alice);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['audience' => 'friends', 'bio' => 'Friends only.']);
        self::assertResponseIsSuccessful();

        // Alice requests Bob.
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/bob/friend-request');
        self::assertResponseIsSuccessful();
        self::assertSame('outgoing', $this->data()['state']);

        // Bob sees an incoming request and accepts it.
        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/community/friends');
        self::assertResponseIsSuccessful();
        $friendshipId = $this->firstIncomingFriendshipId();

        $this->client->jsonRequest('POST', '/api/v1/community/friendships/'.$friendshipId.'/accept');
        self::assertResponseStatusCodeSame(204);

        // Now Bob (a friend) sees Alice's friends-only customization.
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice');
        self::assertResponseIsSuccessful();
        $customization = $this->data()['customization'];
        self::assertIsArray($customization);
        self::assertSame('Friends only.', $customization['bio']);

        // Carol (neither friend nor member) does not.
        $carol = $this->userBySlug('carol');
        $this->loginAs($carol);
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice');
        self::assertResponseIsSuccessful();
        self::assertNull($this->data()['customization']);
    }

    public function testBlockRetractsFriendshipAndHidesProfile(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        // Become friends.
        $this->loginAs($alice);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['audience' => 'friends', 'bio' => 'Hi friends.']);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/bob/friend-request');
        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/community/friends');
        $friendshipId = $this->firstIncomingFriendshipId();
        $this->client->jsonRequest('POST', '/api/v1/community/friendships/'.$friendshipId.'/accept');

        // Alice blocks Bob -> friendship retracted, profile hidden from Bob.
        $this->loginAs($alice);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/bob/block');
        self::assertResponseIsSuccessful();
        self::assertSame('blocking', $this->data()['state']);

        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice');
        self::assertResponseIsSuccessful();
        self::assertNull($this->data()['customization'], 'block overrides the friend audience');
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice/relationship');
        self::assertSame('blocked', $this->data()['state']);

        // Alice unblocks -> relationship clears.
        $this->loginAs($alice);
        $this->client->jsonRequest('DELETE', '/api/v1/community/profiles/bob/block');
        self::assertResponseIsSuccessful();
        self::assertSame('none', $this->data()['state']);
    }

    public function testSelfRequestIsRejected(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->loginAs($alice);

        $this->client->jsonRequest('POST', '/api/v1/community/profiles/alice/friend-request');
        self::assertResponseStatusCodeSame(422);
    }

    public function testFriendsRequiresAuthentication(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/community/friends');
        self::assertResponseStatusCodeSame(401);
    }

    private function firstIncomingFriendshipId(): string
    {
        $incoming = $this->data()['incoming'] ?? null;
        self::assertIsArray($incoming);
        self::assertNotEmpty($incoming);
        $first = $incoming[0] ?? null;
        self::assertIsArray($first);
        $id = $first['friendshipId'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function userBySlug(string $slug): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['slug' => $slug]);
        self::assertInstanceOf(User::class, $user);

        return $user;
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
}
