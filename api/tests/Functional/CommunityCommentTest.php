<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class CommunityCommentTest extends FunctionalTestCase
{
    public function testOwnerCanPostListAndDeleteOwnComment(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->loginAs($alice);

        $this->client->jsonRequest('POST', '/api/v1/community/profiles/alice/comments', ['body' => 'Mon premier mot.']);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice/comments');
        self::assertResponseIsSuccessful();
        $comments = $this->items();
        self::assertCount(1, $comments);
        $first = $comments[0];
        self::assertIsArray($first);
        self::assertSame('Mon premier mot.', $first['body']);
        self::assertTrue($first['canDelete']);
        $id = $first['id'];
        self::assertIsString($id);

        $this->client->jsonRequest('DELETE', '/api/v1/community/comments/'.$id);
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice/comments');
        self::assertCount(0, $this->items());
    }

    public function testNonMemberCannotComment(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $carol = $this->createUser('carol@example.org', slug: 'carol');

        // Make Alice's profile public so the block is the membership rule, not the audience.
        $this->loginAs($alice);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['audience' => 'public']);

        $this->loginAs($carol);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/alice/comments', ['body' => 'Coucou']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCommentsHiddenFromNonFriendOnFriendsProfile(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $carol = $this->createUser('carol@example.org', slug: 'carol');

        $this->loginAs($alice);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['audience' => 'friends']);

        $this->loginAs($carol);
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice/comments');
        self::assertResponseStatusCodeSame(403);
    }

    public function testReportingAComment(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        $this->loginAs($alice);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/alice/comments', ['body' => 'Hello']);
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice/comments');
        $first = $this->items()[0] ?? null;
        self::assertIsArray($first);
        $id = $first['id'] ?? null;
        self::assertIsString($id);

        $this->loginAs($bob);
        $this->client->jsonRequest('POST', '/api/v1/community/comments/'.$id.'/report', ['reason' => 'spam']);
        self::assertResponseStatusCodeSame(204);
        // Idempotent.
        $this->client->jsonRequest('POST', '/api/v1/community/comments/'.$id.'/report', ['reason' => 'spam']);
        self::assertResponseStatusCodeSame(204);
    }

    public function testRateLimit(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->loginAs($alice);

        for ($i = 0; $i < 5; ++$i) {
            $this->client->jsonRequest('POST', '/api/v1/community/profiles/alice/comments', ['body' => 'msg '.$i]);
            self::assertResponseStatusCodeSame(201);
        }
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/alice/comments', ['body' => 'one too many']);
        self::assertResponseStatusCodeSame(429);
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
