<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\FriendGroup;
use App\Community\Domain\Entity\FriendGroupMember;

interface FriendGroupRepositoryInterface
{
    public function find(string $groupId): ?FriendGroup;

    /** @return list<FriendGroup> the owner's groups, oldest first */
    public function groupsOf(string $ownerId): array;

    public function count(string $ownerId): int;

    /**
     * Who is in each of the owner's groups, by when they were added.
     *
     * @return array<string, list<string>> group id => member ids
     */
    public function membersByGroup(string $ownerId): array;

    public function findMember(string $groupId, string $memberId): ?FriendGroupMember;

    public function countMembers(string $groupId): int;

    public function save(FriendGroup $group): void;

    public function saveMember(FriendGroupMember $member): void;

    public function removeMember(FriendGroupMember $member): void;

    /** The group and everyone in it. */
    public function remove(FriendGroup $group): void;

    /** Both ways: an ended friendship or a block takes each out of the other's groups. */
    public function removeBetween(string $a, string $b): void;
}
