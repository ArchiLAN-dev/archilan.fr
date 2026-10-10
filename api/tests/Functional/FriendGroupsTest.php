<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\FriendGroup;
use App\Community\Domain\Entity\FriendGroupMember;
use App\Community\Domain\Entity\Friendship;
use App\Identity\Domain\Entity\User;

/**
 * Story 43.13: a member's private groups of friends.
 */
final class FriendGroupsTest extends FunctionalTestCase
{
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewer = $this->createUser('viewer@example.org', displayName: 'Viewer', slug: 'viewer');
    }

    public function testTheOwnerCreatesRenamesFillsAndDeletesAGroup(): void
    {
        $alice = $this->friend('alice');
        $bob = $this->friend('bob');

        $this->loginAs($this->viewer);
        $this->client->request('POST', '/api/v1/community/friend-groups', content: '{"name":"  La team   du jeudi "}');
        self::assertResponseStatusCodeSame(201);
        $group = $this->onlyGroup();
        self::assertSame('La team du jeudi', $group['name']);
        $id = $group['id'];
        self::assertIsString($id);

        $this->client->request('PUT', '/api/v1/community/friend-groups/'.$id.'/members/'.$alice->getId());
        self::assertResponseIsSuccessful();
        $this->client->request('PUT', '/api/v1/community/friend-groups/'.$id.'/members/'.$bob->getId());
        $this->client->request('PUT', '/api/v1/community/friend-groups/'.$id.'/members/'.$bob->getId());
        self::assertEqualsCanonicalizing([$alice->getId(), $bob->getId()], $this->onlyGroup()['memberIds'], 'added once');

        $this->client->request('DELETE', '/api/v1/community/friend-groups/'.$id.'/members/'.$alice->getId());
        self::assertSame([$bob->getId()], $this->onlyGroup()['memberIds']);

        $this->client->request('PATCH', '/api/v1/community/friend-groups/'.$id, content: '{"name":"Vendredi"}');
        self::assertResponseIsSuccessful();
        self::assertSame('Vendredi', $this->onlyGroup()['name']);
        $this->client->request('PATCH', '/api/v1/community/friend-groups/'.$id, content: '{"name":"   "}');
        self::assertResponseStatusCodeSame(422);

        $this->client->request('DELETE', '/api/v1/community/friend-groups/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->myGroups());
        self::assertSame(0, $this->entityManager->getRepository(FriendGroupMember::class)->count([]));
    }

    public function testOnlyFriendsGoInAndTheGroupStaysPrivate(): void
    {
        $alice = $this->friend('alice');
        $stranger = $this->createUser('stranger@example.org', slug: 'stranger');
        $group = $this->group('Jeudi', [$alice]);

        $this->loginAs($this->viewer);
        $this->client->request('PUT', '/api/v1/community/friend-groups/'.$group->getId().'/members/'.$stranger->getId());
        self::assertResponseStatusCodeSame(422);

        $this->loginAs($alice);
        self::assertSame([], $this->myGroups(), 'a friend never sees the groups they are in');
        $this->client->request('PATCH', '/api/v1/community/friend-groups/'.$group->getId(), content: '{"name":"Pris"}');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('DELETE', '/api/v1/community/friend-groups/'.$group->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testGroupsAndMembersHaveACap(): void
    {
        for ($i = 0; $i < FriendGroup::MAX_PER_OWNER; ++$i) {
            $this->entityManager->persist(FriendGroup::create($this->viewer->getId(), 'G'.$i, new \DateTimeImmutable()));
        }
        $full = FriendGroup::create($this->viewer->getId(), 'Plein', new \DateTimeImmutable());
        $this->entityManager->persist($full);
        $this->entityManager->flush();
        for ($i = 0; $i < FriendGroup::MAX_MEMBERS; ++$i) {
            $this->entityManager->persist(FriendGroupMember::add($full, 'member'.$i, new \DateTimeImmutable()));
        }
        $this->entityManager->flush();
        $alice = $this->friend('alice');

        $this->loginAs($this->viewer);
        $this->client->request('POST', '/api/v1/community/friend-groups', content: '{"name":"Un de trop"}');
        self::assertResponseStatusCodeSame(422);
        $this->client->request('PUT', '/api/v1/community/friend-groups/'.$full->getId().'/members/'.$alice->getId());
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnEndedFriendshipOrABlockTakesThePersonOutOfEveryGroup(): void
    {
        $alice = $this->friend('alice');
        $bob = $this->friend('bob');
        $this->group('Jeudi', [$alice, $bob]);
        $this->group('Vendredi', [$alice]);
        $theirs = FriendGroup::create($bob->getId(), 'Chez Bob', new \DateTimeImmutable());
        $this->entityManager->persist($theirs);
        $this->entityManager->persist(FriendGroupMember::add($theirs, $this->viewer->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->loginAs($this->viewer);
        $this->client->request('DELETE', '/api/v1/community/profiles/alice/friendship');
        self::assertResponseIsSuccessful();
        $this->loginAs($bob);
        $this->client->request('POST', '/api/v1/community/profiles/viewer/block');
        self::assertResponseIsSuccessful();

        self::assertSame(0, $this->entityManager->getRepository(FriendGroupMember::class)->count([]));
        self::assertSame(3, $this->entityManager->getRepository(FriendGroup::class)->count([]), 'the groups stay, empty');
    }

    public function testTheDirectoryFiltersOnAGroup(): void
    {
        $this->friend('alice');
        $bob = $this->friend('bob');
        $group = $this->group('Jeudi', [$bob]);

        $this->loginAs($this->viewer);
        $this->client->request('GET', '/api/v1/community/directory?friendsOnly=1&group='.$group->getId());
        self::assertResponseIsSuccessful();
        $rows = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($rows);
        self::assertSame(['bob'], array_column($rows, 'slug'));

        $this->loginAs($bob);
        $this->client->request('GET', '/api/v1/community/directory?friendsOnly=1&group='.$group->getId());
        self::assertSame([], $this->decodedJsonResponse()['data'] ?? null, 'another member\'s group is empty');
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

    /**
     * @param list<User> $members
     */
    private function group(string $name, array $members): FriendGroup
    {
        $group = FriendGroup::create($this->viewer->getId(), $name, new \DateTimeImmutable());
        $this->entityManager->persist($group);
        foreach ($members as $member) {
            $this->entityManager->persist(FriendGroupMember::add($group, $member->getId(), new \DateTimeImmutable()));
        }
        $this->entityManager->flush();

        return $group;
    }

    /**
     * @return array<mixed>
     */
    private function myGroups(): array
    {
        $this->client->request('GET', '/api/v1/community/friend-groups');
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return array<mixed>
     */
    private function onlyGroup(): array
    {
        $groups = $this->myGroups();
        self::assertCount(1, $groups);
        $group = $groups[0] ?? null;
        self::assertIsArray($group);

        return $group;
    }
}
