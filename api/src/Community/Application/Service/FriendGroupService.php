<?php

declare(strict_types=1);

namespace App\Community\Application\Service;

use App\Community\Domain\Entity\FriendGroup;
use App\Community\Domain\Entity\FriendGroupMember;
use App\Community\Domain\Entity\Friendship;
use App\Community\Domain\Repository\FriendGroupRepositoryInterface;
use App\Community\Domain\Repository\FriendshipRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * A member's private groups of friends (story 43.13): create, rename, delete, put friends in and take them out. Only
 * accepted friends go in; a group belongs to its owner alone, so another member's group reads as not found.
 *
 * Every write answers with an outcome and the owner's groups as they now stand.
 */
final readonly class FriendGroupService
{
    public const string OK = 'ok';
    public const string NOT_FOUND = 'not_found';
    public const string INVALID_NAME = 'invalid_name';
    public const string GROUP_LIMIT = 'group_limit';
    public const string MEMBER_LIMIT = 'member_limit';
    public const string NOT_FRIEND = 'not_friend';

    public const int MAX_GROUPS = FriendGroup::MAX_PER_OWNER;
    public const int MAX_MEMBERS = FriendGroup::MAX_MEMBERS;
    public const int MAX_NAME_LENGTH = FriendGroup::MAX_NAME_LENGTH;

    public function __construct(
        private FriendGroupRepositoryInterface $groups,
        private FriendshipRepositoryInterface $friendships,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, memberIds: list<string>}> by name
     */
    public function groupsOf(string $ownerId): array
    {
        $members = $this->groups->membersByGroup($ownerId);
        $list = [];
        foreach ($this->groups->groupsOf($ownerId) as $group) {
            $list[] = ['id' => $group->getId(), 'name' => $group->getName(), 'memberIds' => $members[$group->getId()] ?? []];
        }
        usort($list, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $list;
    }

    /**
     * The members of one of the owner's groups; null for a group that is not theirs.
     *
     * @return list<string>|null
     */
    public function memberIds(string $ownerId, string $groupId): ?array
    {
        $group = $this->groups->find($groupId);
        if (!$group instanceof FriendGroup || !$group->isOwnedBy($ownerId)) {
            return null;
        }

        return $this->groups->membersByGroup($ownerId)[$groupId] ?? [];
    }

    /**
     * @return array{outcome: string, groups: list<array{id: string, name: string, memberIds: list<string>}>}
     */
    public function create(string $ownerId, string $name): array
    {
        $name = FriendGroup::normalizeName($name);
        if (null === $name) {
            return $this->answer(self::INVALID_NAME, $ownerId);
        }
        if ($this->groups->count($ownerId) >= FriendGroup::MAX_PER_OWNER) {
            return $this->answer(self::GROUP_LIMIT, $ownerId);
        }
        $this->groups->save(FriendGroup::create($ownerId, $name, $this->clock->now()));

        return $this->answer(self::OK, $ownerId);
    }

    /**
     * @return array{outcome: string, groups: list<array{id: string, name: string, memberIds: list<string>}>}
     */
    public function rename(string $ownerId, string $groupId, string $name): array
    {
        $group = $this->owned($ownerId, $groupId);
        if (null === $group) {
            return $this->answer(self::NOT_FOUND, $ownerId);
        }
        $name = FriendGroup::normalizeName($name);
        if (null === $name) {
            return $this->answer(self::INVALID_NAME, $ownerId);
        }
        $group->rename($name, $this->clock->now());
        $this->groups->save($group);

        return $this->answer(self::OK, $ownerId);
    }

    /**
     * @return array{outcome: string, groups: list<array{id: string, name: string, memberIds: list<string>}>}
     */
    public function delete(string $ownerId, string $groupId): array
    {
        $group = $this->owned($ownerId, $groupId);
        if (null === $group) {
            return $this->answer(self::NOT_FOUND, $ownerId);
        }
        $this->groups->remove($group);

        return $this->answer(self::OK, $ownerId);
    }

    /**
     * Puts a friend in the group - idempotent.
     *
     * @return array{outcome: string, groups: list<array{id: string, name: string, memberIds: list<string>}>}
     */
    public function addMember(string $ownerId, string $groupId, string $memberId): array
    {
        $group = $this->owned($ownerId, $groupId);
        if (null === $group) {
            return $this->answer(self::NOT_FOUND, $ownerId);
        }
        $friendship = $this->friendships->findBetween($ownerId, $memberId);
        if ($memberId === $ownerId || !$friendship instanceof Friendship || !$friendship->isAccepted()) {
            return $this->answer(self::NOT_FRIEND, $ownerId);
        }
        if (null !== $this->groups->findMember($groupId, $memberId)) {
            return $this->answer(self::OK, $ownerId);
        }
        if ($this->groups->countMembers($groupId) >= FriendGroup::MAX_MEMBERS) {
            return $this->answer(self::MEMBER_LIMIT, $ownerId);
        }

        try {
            $this->groups->saveMember(FriendGroupMember::add($group, $memberId, $this->clock->now()));
        } catch (UniqueConstraintViolationException) {
            // Added twice at the same instant - idempotent.
        }

        return $this->answer(self::OK, $ownerId);
    }

    /**
     * Takes a friend out of the group - idempotent.
     *
     * @return array{outcome: string, groups: list<array{id: string, name: string, memberIds: list<string>}>}
     */
    public function removeMember(string $ownerId, string $groupId, string $memberId): array
    {
        if (null === $this->owned($ownerId, $groupId)) {
            return $this->answer(self::NOT_FOUND, $ownerId);
        }
        $member = $this->groups->findMember($groupId, $memberId);
        if ($member instanceof FriendGroupMember) {
            $this->groups->removeMember($member);
        }

        return $this->answer(self::OK, $ownerId);
    }

    private function owned(string $ownerId, string $groupId): ?FriendGroup
    {
        $group = $this->groups->find($groupId);

        return $group instanceof FriendGroup && $group->isOwnedBy($ownerId) ? $group : null;
    }

    /**
     * @return array{outcome: string, groups: list<array{id: string, name: string, memberIds: list<string>}>}
     */
    private function answer(string $outcome, string $ownerId): array
    {
        return ['outcome' => $outcome, 'groups' => $this->groupsOf($ownerId)];
    }
}
