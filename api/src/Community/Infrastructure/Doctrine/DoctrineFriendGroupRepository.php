<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Entity\FriendGroup;
use App\Community\Domain\Entity\FriendGroupMember;
use App\Community\Domain\Repository\FriendGroupRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineFriendGroupRepository implements FriendGroupRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $groupId): ?FriendGroup
    {
        return $this->entityManager->find(FriendGroup::class, $groupId);
    }

    public function groupsOf(string $ownerId): array
    {
        return $this->entityManager->getRepository(FriendGroup::class)->findBy(['ownerId' => $ownerId], ['createdAt' => 'ASC']);
    }

    public function count(string $ownerId): int
    {
        return $this->entityManager->getRepository(FriendGroup::class)->count(['ownerId' => $ownerId]);
    }

    public function membersByGroup(string $ownerId): array
    {
        $members = [];
        foreach ($this->entityManager->getRepository(FriendGroupMember::class)->findBy(['ownerId' => $ownerId], ['addedAt' => 'ASC']) as $member) {
            $members[$member->getGroupId()][] = $member->getMemberId();
        }

        return $members;
    }

    public function findMember(string $groupId, string $memberId): ?FriendGroupMember
    {
        return $this->entityManager->getRepository(FriendGroupMember::class)->findOneBy(['groupId' => $groupId, 'memberId' => $memberId]);
    }

    public function countMembers(string $groupId): int
    {
        return $this->entityManager->getRepository(FriendGroupMember::class)->count(['groupId' => $groupId]);
    }

    public function save(FriendGroup $group): void
    {
        $this->entityManager->persist($group);
        $this->entityManager->flush();
    }

    public function saveMember(FriendGroupMember $member): void
    {
        $this->entityManager->persist($member);
        $this->entityManager->flush();
    }

    public function removeMember(FriendGroupMember $member): void
    {
        $this->entityManager->remove($member);
        $this->entityManager->flush();
    }

    public function remove(FriendGroup $group): void
    {
        foreach ($this->entityManager->getRepository(FriendGroupMember::class)->findBy(['groupId' => $group->getId()]) as $member) {
            $this->entityManager->remove($member);
        }
        $this->entityManager->remove($group);
        $this->entityManager->flush();
    }

    public function removeBetween(string $a, string $b): void
    {
        $repository = $this->entityManager->getRepository(FriendGroupMember::class);
        foreach ([...$repository->findBy(['ownerId' => $a, 'memberId' => $b]), ...$repository->findBy(['ownerId' => $b, 'memberId' => $a])] as $member) {
            $this->entityManager->remove($member);
        }
        $this->entityManager->flush();
    }
}
