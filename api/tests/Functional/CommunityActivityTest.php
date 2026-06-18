<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\ActivityEntry;

final class CommunityActivityTest extends FunctionalTestCase
{
    public function testAcceptingAFriendshipRecordsAnActivityEntry(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        $this->loginAs($alice);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/bob/friend-request');
        self::assertResponseIsSuccessful();

        $this->loginAs($bob);
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

        // Bob (the accepter) gets a 'friendship' activity entry referencing the friendship.
        $entries = $this->entityManager->getRepository(ActivityEntry::class)
            ->findBy(['actorId' => $bob->getId(), 'type' => ActivityEntry::TYPE_FRIENDSHIP]);
        self::assertCount(1, $entries);
        self::assertSame($friendshipId, $entries[0]->getSubjectRef());
    }
}
