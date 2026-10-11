<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A friend in one of a member's groups (story 43.13). The owner is repeated here so an ended friendship or a block
 * takes the friend out of all the owner's groups in one go.
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_friend_group_member')]
#[ORM\UniqueConstraint(name: 'uniq_community_friend_group_member', columns: ['group_id', 'member_id'])]
#[ORM\Index(name: 'idx_community_friend_group_member_owner', columns: ['owner_id', 'member_id'])]
final class FriendGroupMember
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'group_id', type: 'string', length: 32)]
        private string $groupId,
        #[ORM\Column(name: 'owner_id', type: 'string', length: 32)]
        private string $ownerId,
        #[ORM\Column(name: 'member_id', type: 'string', length: 32)]
        private string $memberId,
        #[ORM\Column(name: 'added_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $addedAt,
    ) {
    }

    public static function add(FriendGroup $group, string $memberId, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $group->getId(), $group->getOwnerId(), $memberId, $now);
    }

    public function getGroupId(): string
    {
        return $this->groupId;
    }

    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function getMemberId(): string
    {
        return $this->memberId;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }
}
