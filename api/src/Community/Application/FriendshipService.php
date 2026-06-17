<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Block;
use App\Community\Domain\BlockRepositoryInterface;
use App\Community\Domain\Friendship;
use App\Community\Domain\FriendshipRepositoryInterface;

/**
 * Friendships + blocks (story 30.7): request/accept/decline/remove, block/unblock, and the relationship
 * + friends-list reads. Block is the strongest action - it retracts any friendship and prevents
 * re-interaction. Cohesive read+write service in the local PersonalRuns style.
 */
final readonly class FriendshipService
{
    public function __construct(
        private FriendshipRepositoryInterface $friendships,
        private BlockRepositoryInterface $blocks,
        private CommunityUserDirectoryQueryInterface $directory,
    ) {
    }

    public function requestFriend(string $userId, string $targetUserId): string
    {
        if ($userId === $targetUserId) {
            return 'self';
        }
        if ($this->blocks->existsEitherWay($userId, $targetUserId)) {
            return 'blocked';
        }

        $now = new \DateTimeImmutable();
        $existing = $this->friendships->findBetween($userId, $targetUserId);

        if ($existing instanceof Friendship) {
            if ($existing->isAccepted()) {
                return 'ok';
            }
            if ($existing->isPending()) {
                // A pending request the other way round becomes a mutual accept.
                if ($existing->isAddressee($userId)) {
                    $existing->accept($now);
                    $this->friendships->save($existing);
                }

                return 'ok';
            }
            // Declined -> a fresh request re-opens it.
            $existing->reopen($userId, $targetUserId, $now);
            $this->friendships->save($existing);

            return 'ok';
        }

        $this->friendships->save(Friendship::request($userId, $targetUserId, $now));

        return 'ok';
    }

    public function accept(string $userId, string $friendshipId): string
    {
        $friendship = $this->friendships->findById($friendshipId);
        if (!$friendship instanceof Friendship || !$friendship->isPending() || !$friendship->isAddressee($userId)) {
            return 'not_found';
        }

        $friendship->accept(new \DateTimeImmutable());
        $this->friendships->save($friendship);

        return 'ok';
    }

    public function decline(string $userId, string $friendshipId): string
    {
        $friendship = $this->friendships->findById($friendshipId);
        if (!$friendship instanceof Friendship || !$friendship->isPending() || !$friendship->isAddressee($userId)) {
            return 'not_found';
        }

        $friendship->decline(new \DateTimeImmutable());
        $this->friendships->save($friendship);

        return 'ok';
    }

    /** Unfriend or cancel a pending request - idempotent. */
    public function removeFriendship(string $userId, string $targetUserId): void
    {
        $friendship = $this->friendships->findBetween($userId, $targetUserId);
        if ($friendship instanceof Friendship && $friendship->involves($userId)) {
            $this->friendships->remove($friendship);
        }
    }

    public function block(string $userId, string $targetUserId): string
    {
        if ($userId === $targetUserId) {
            return 'self';
        }

        // Block retracts any existing/pending friendship.
        $friendship = $this->friendships->findBetween($userId, $targetUserId);
        if ($friendship instanceof Friendship) {
            $this->friendships->remove($friendship);
        }

        if (null === $this->blocks->find($userId, $targetUserId)) {
            $this->blocks->save(Block::create($userId, $targetUserId, new \DateTimeImmutable()));
        }

        return 'ok';
    }

    public function unblock(string $userId, string $targetUserId): void
    {
        $block = $this->blocks->find($userId, $targetUserId);
        if ($block instanceof Block) {
            $this->blocks->remove($block);
        }
    }

    /**
     * @return array{state: string, friendshipId: string|null}
     */
    public function relationship(string $userId, string $targetUserId): array
    {
        if ($userId === $targetUserId) {
            return ['state' => 'self', 'friendshipId' => null];
        }
        if (null !== $this->blocks->find($userId, $targetUserId)) {
            return ['state' => 'blocking', 'friendshipId' => null];
        }
        if (null !== $this->blocks->find($targetUserId, $userId)) {
            return ['state' => 'blocked', 'friendshipId' => null];
        }

        $friendship = $this->friendships->findBetween($userId, $targetUserId);
        if (!$friendship instanceof Friendship) {
            return ['state' => 'none', 'friendshipId' => null];
        }
        if ($friendship->isAccepted()) {
            return ['state' => 'friends', 'friendshipId' => $friendship->getId()];
        }
        if ($friendship->isPending()) {
            return $friendship->isAddressee($userId)
                ? ['state' => 'incoming', 'friendshipId' => $friendship->getId()]
                : ['state' => 'outgoing', 'friendshipId' => $friendship->getId()];
        }

        return ['state' => 'none', 'friendshipId' => null];
    }

    /**
     * @return array{
     *     friends: list<array{userId: string, slug: string, displayName: string|null, avatarUrl: string|null}>,
     *     incoming: list<array{friendshipId: string, userId: string, slug: string, displayName: string|null, avatarUrl: string|null}>,
     *     outgoing: list<array{userId: string, slug: string, displayName: string|null, avatarUrl: string|null}>
     * }
     */
    public function friends(string $userId): array
    {
        $accepted = $this->friendships->findAccepted($userId);
        $incoming = $this->friendships->findIncomingPending($userId);
        $outgoing = $this->friendships->findOutgoingPending($userId);

        $ids = [];
        foreach ($accepted as $f) {
            $ids[] = $f->otherParty($userId);
        }
        foreach ($incoming as $f) {
            $ids[] = $f->getRequesterId();
        }
        foreach ($outgoing as $f) {
            $ids[] = $f->getAddresseeId();
        }
        $cards = $this->directory->cards(array_values(array_unique($ids)));

        $friends = [];
        foreach ($accepted as $f) {
            $card = $cards[$f->otherParty($userId)] ?? null;
            if (null !== $card) {
                $friends[] = $card;
            }
        }

        $incomingList = [];
        foreach ($incoming as $f) {
            $card = $cards[$f->getRequesterId()] ?? null;
            if (null !== $card) {
                $incomingList[] = ['friendshipId' => $f->getId(), ...$card];
            }
        }

        $outgoingList = [];
        foreach ($outgoing as $f) {
            $card = $cards[$f->getAddresseeId()] ?? null;
            if (null !== $card) {
                $outgoingList[] = $card;
            }
        }

        return ['friends' => $friends, 'incoming' => $incomingList, 'outgoing' => $outgoingList];
    }
}
