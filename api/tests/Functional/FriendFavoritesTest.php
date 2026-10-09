<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\FriendFavorite;
use App\Community\Domain\Entity\Friendship;
use App\Identity\Domain\Entity\User;

/**
 * Story 43.11a: starred friends, at the top of the viewer's lists and never shown to the friend.
 */
final class FriendFavoritesTest extends FunctionalTestCase
{
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewer = $this->createUser('viewer@example.org', displayName: 'Viewer', slug: 'viewer');
    }

    public function testAStarredFriendComesFirstAndTheFriendNeverKnows(): void
    {
        $this->friend('alice');
        $bob = $this->friend('bob');

        $this->loginAs($this->viewer);
        $this->client->request('POST', '/api/v1/community/profiles/bob/favorite');
        self::assertResponseIsSuccessful();
        self::assertSame(['state' => 'friends', 'favorite' => true], $this->relationshipOf($this->data()));

        self::assertSame([['bob', true], ['alice', false]], $this->friendsList(), 'the star first, then the usual order');

        $this->client->request('GET', '/api/v1/community/directory?friendsOnly=1&sort=recent');
        self::assertSame(['bob', 'alice'], array_column($this->rows($this->data()), 'slug'));

        $this->loginAs($bob);
        $this->client->request('GET', '/api/v1/community/profiles/viewer/relationship');
        self::assertSame(['state' => 'friends', 'favorite' => false], $this->relationshipOf($this->data()), 'one way, never shown to the friend');
        $this->client->request('GET', '/api/v1/community/friends');
        self::assertStringNotContainsString('"isFavorite":true', (string) $this->client->getResponse()->getContent());

        $this->loginAs($this->viewer);
        $this->client->request('DELETE', '/api/v1/community/profiles/bob/favorite');
        self::assertSame(['state' => 'friends', 'favorite' => false], $this->relationshipOf($this->data()));
    }

    public function testOnlyAFriendCanBeStarredAndNotPastTheLimit(): void
    {
        $this->createUser('stranger@example.org', slug: 'stranger');
        $this->loginAs($this->viewer);
        $this->client->request('POST', '/api/v1/community/profiles/stranger/favorite');
        self::assertResponseStatusCodeSame(422);

        for ($i = 0; $i < FriendFavorite::MAX_PER_USER; ++$i) {
            $friend = $this->friend('friend'.$i);
            $this->entityManager->persist(FriendFavorite::create($this->viewer->getId(), $friend->getId(), new \DateTimeImmutable()));
        }
        $this->friend('onemore');
        $this->entityManager->flush();

        $this->client->request('POST', '/api/v1/community/profiles/onemore/favorite');
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/api/v1/community/profiles/friend0/favorite');
        self::assertResponseIsSuccessful('starring an already starred friend stays fine at the limit');
    }

    public function testAnEndedFriendshipOrABlockTakesTheStarAwayBothWays(): void
    {
        $alice = $this->friend('alice');
        $bob = $this->friend('bob');
        foreach ([$alice, $bob] as $friend) {
            $this->entityManager->persist(FriendFavorite::create($this->viewer->getId(), $friend->getId(), new \DateTimeImmutable()));
            $this->entityManager->persist(FriendFavorite::create($friend->getId(), $this->viewer->getId(), new \DateTimeImmutable()));
        }
        $this->entityManager->flush();

        $this->loginAs($this->viewer);
        $this->client->request('DELETE', '/api/v1/community/profiles/alice/friendship');
        self::assertResponseIsSuccessful();
        $this->loginAs($bob);
        $this->client->request('POST', '/api/v1/community/profiles/viewer/block');
        self::assertResponseIsSuccessful();

        self::assertSame(0, $this->entityManager->getRepository(FriendFavorite::class)->count([]));
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function friend(string $slug): User
    {
        $user = $this->createUser($slug.'@example.org', displayName: ucfirst($slug), slug: $slug);
        $friendship = Friendship::request($user->getId(), $this->viewer->getId(), new \DateTimeImmutable());
        $friendship->accept(new \DateTimeImmutable());
        $this->entityManager->persist($friendship);
        $this->entityManager->flush();

        return $user;
    }

    private function data(): mixed
    {
        return $this->decodedJsonResponse()['data'] ?? null;
    }

    /**
     * @return array{state: mixed, favorite: mixed}
     */
    private function relationshipOf(mixed $data): array
    {
        self::assertIsArray($data);

        return ['state' => $data['state'] ?? null, 'favorite' => $data['favorite'] ?? null];
    }

    /**
     * @return list<array<mixed>>
     */
    private function rows(mixed $rows): array
    {
        self::assertIsArray($rows);
        $list = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $list[] = $row;
        }

        return $list;
    }

    /**
     * @return list<array{mixed, mixed}>
     */
    private function friendsList(): array
    {
        $this->client->request('GET', '/api/v1/community/friends');
        $data = $this->data();
        self::assertIsArray($data);

        return array_map(static fn (array $row): array => [$row['slug'] ?? null, $row['isFavorite'] ?? null], $this->rows($data['friends'] ?? null));
    }
}
