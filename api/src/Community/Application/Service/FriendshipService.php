<?php

declare(strict_types=1);

namespace App\Community\Application\Service;

use App\Community\Application\Command\RecordActivity;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Support\Notifier;
use App\Community\Domain\Entity\ActivityEntry;
use App\Community\Domain\Entity\Block;
use App\Community\Domain\Entity\FriendFavorite;
use App\Community\Domain\Entity\Friendship;
use App\Community\Domain\Entity\Notification;
use App\Community\Domain\Repository\BlockRepositoryInterface;
use App\Community\Domain\Repository\FriendFavoriteRepositoryInterface;
use App\Community\Domain\Repository\FriendGroupRepositoryInterface;
use App\Community\Domain\Repository\FriendshipRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * Friendships + blocks (story 30.7): request/accept/decline/remove, block/unblock, and the relationship
 * + friends-list reads. Block is the strongest action - it retracts any friendship and prevents
 * re-interaction. Cohesive read+write service in the local PersonalRuns style.
 *
 * Favorites (story 43.11a): a member stars some of their friends, who go to the top of their lists. The star is the
 * starrer's alone: it only appears in what is read for them, and goes away with the friendship. So does a place in
 * the other's friend groups (story 43.13).
 */
final readonly class FriendshipService
{
    public function __construct(
        private FriendshipRepositoryInterface $friendships,
        private BlockRepositoryInterface $blocks,
        private FriendFavoriteRepositoryInterface $favorites,
        private FriendGroupRepositoryInterface $groups,
        private CommunityUserDirectoryQueryInterface $directory,
        private RecordActivity $recordActivity,
        private Notifier $notifier,
        private ClockInterface $clock,
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

        $now = $this->clock->now();
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
                    $this->recordFriendshipActivity($existing, $userId, $now);
                    $this->notifier->notify(
                        $existing->otherParty($userId),
                        Notification::TYPE_FRIEND_REQUEST_ACCEPTED,
                        ['fromUserId' => $userId],
                    );
                }

                return 'ok';
            }
            // Declined -> a fresh request re-opens it.
            $existing->reopen($userId, $targetUserId, $now);
            $this->friendships->save($existing);
            $this->notifier->notify($targetUserId, Notification::TYPE_FRIEND_REQUEST_RECEIVED, ['fromUserId' => $userId]);

            return 'ok';
        }

        try {
            $this->friendships->save(Friendship::request($userId, $targetUserId, $now));
            $this->notifier->notify($targetUserId, Notification::TYPE_FRIEND_REQUEST_RECEIVED, ['fromUserId' => $userId]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the pair first - idempotent.
        }

        return 'ok';
    }

    public function accept(string $userId, string $friendshipId): string
    {
        $friendship = $this->friendships->findById($friendshipId);
        if (!$friendship instanceof Friendship || !$friendship->isPending() || !$friendship->isAddressee($userId)) {
            return 'not_found';
        }

        $now = $this->clock->now();
        $friendship->accept($now);
        $this->friendships->save($friendship);
        $this->recordFriendshipActivity($friendship, $userId, $now);
        $this->notifier->notify(
            $friendship->otherParty($userId),
            Notification::TYPE_FRIEND_REQUEST_ACCEPTED,
            ['fromUserId' => $userId],
        );

        return 'ok';
    }

    public function decline(string $userId, string $friendshipId): string
    {
        $friendship = $this->friendships->findById($friendshipId);
        if (!$friendship instanceof Friendship || !$friendship->isPending() || !$friendship->isAddressee($userId)) {
            return 'not_found';
        }

        $friendship->decline($this->clock->now());
        $this->friendships->save($friendship);

        return 'ok';
    }

    /** Unfriend or cancel a pending request - idempotent. */
    public function removeFriendship(string $userId, string $targetUserId): void
    {
        $friendship = $this->friendships->findBetween($userId, $targetUserId);
        if ($friendship instanceof Friendship && $friendship->involves($userId)) {
            $this->friendships->remove($friendship);
            $this->favorites->removeBetween($userId, $targetUserId);
            // Story 43.13: out of each other's groups too.
            $this->groups->removeBetween($userId, $targetUserId);
        }
    }

    /**
     * Star a friend - idempotent. `not_friend` for anyone else, `limit` past the maximum.
     */
    public function favorite(string $userId, string $targetUserId): string
    {
        if ($userId === $targetUserId) {
            return 'self';
        }
        $friendship = $this->friendships->findBetween($userId, $targetUserId);
        if (!$friendship instanceof Friendship || !$friendship->isAccepted()) {
            return 'not_friend';
        }
        if (null !== $this->favorites->find($userId, $targetUserId)) {
            return 'ok';
        }
        if ($this->favorites->count($userId) >= FriendFavorite::MAX_PER_USER) {
            return 'limit';
        }

        try {
            $this->favorites->save(FriendFavorite::create($userId, $targetUserId, $this->clock->now()));
        } catch (UniqueConstraintViolationException) {
            // A concurrent star - idempotent.
        }

        return 'ok';
    }

    /** Take the star away - idempotent. */
    public function unfavorite(string $userId, string $targetUserId): void
    {
        $favorite = $this->favorites->find($userId, $targetUserId);
        if ($favorite instanceof FriendFavorite) {
            $this->favorites->remove($favorite);
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
        $this->favorites->removeBetween($userId, $targetUserId);
        $this->groups->removeBetween($userId, $targetUserId);

        if (null === $this->blocks->find($userId, $targetUserId)) {
            try {
                $this->blocks->save(Block::create($userId, $targetUserId, $this->clock->now()));
            } catch (UniqueConstraintViolationException) {
                // Concurrent block - idempotent.
            }
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

    /** Record a feed entry for the user who accepted, referencing the friendship (idempotent). */
    private function recordFriendshipActivity(Friendship $friendship, string $accepterId, \DateTimeImmutable $now): void
    {
        $this->recordActivity->record(
            $accepterId,
            ActivityEntry::TYPE_FRIENDSHIP,
            $friendship->getId(),
            $now,
            ['withUserId' => $friendship->otherParty($accepterId)],
        );
    }

    /**
     * `favorite`: the viewer starred this friend (story 43.11a); always false outside a friendship.
     *
     * @return array{state: string, friendshipId: string|null, favorite: bool}
     */
    public function relationship(string $userId, string $targetUserId): array
    {
        if ($userId === $targetUserId) {
            return ['state' => 'self', 'friendshipId' => null, 'favorite' => false];
        }
        if (null !== $this->blocks->find($userId, $targetUserId)) {
            return ['state' => 'blocking', 'friendshipId' => null, 'favorite' => false];
        }
        if (null !== $this->blocks->find($targetUserId, $userId)) {
            return ['state' => 'blocked', 'friendshipId' => null, 'favorite' => false];
        }

        $friendship = $this->friendships->findBetween($userId, $targetUserId);
        if (!$friendship instanceof Friendship) {
            return ['state' => 'none', 'friendshipId' => null, 'favorite' => false];
        }
        if ($friendship->isAccepted()) {
            return [
                'state' => 'friends',
                'friendshipId' => $friendship->getId(),
                'favorite' => null !== $this->favorites->find($userId, $targetUserId),
            ];
        }
        if ($friendship->isPending()) {
            return $friendship->isAddressee($userId)
                ? ['state' => 'incoming', 'friendshipId' => $friendship->getId(), 'favorite' => false]
                : ['state' => 'outgoing', 'friendshipId' => $friendship->getId(), 'favorite' => false];
        }

        return ['state' => 'none', 'friendshipId' => null, 'favorite' => false];
    }

    /**
     * @return array{
     *     friends: list<array{userId: string, slug: string, displayName: string|null, avatarUrl: string|null, avatarAnimatedUrl: string|null, avatarFraming: array{x: int, y: int, zoom: int}|null, avatarFrame: string|null, nameStyle: string|null, isFavorite: bool}>,
     *     incoming: list<array{friendshipId: string, userId: string, slug: string, displayName: string|null, avatarUrl: string|null, avatarAnimatedUrl: string|null, avatarFraming: array{x: int, y: int, zoom: int}|null, avatarFrame: string|null, nameStyle: string|null}>,
     *     outgoing: list<array{userId: string, slug: string, displayName: string|null, avatarUrl: string|null, avatarAnimatedUrl: string|null, avatarFraming: array{x: int, y: int, zoom: int}|null, avatarFrame: string|null, nameStyle: string|null}>
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

        // Starred friends first (story 43.11a), the existing order kept within each group.
        $favoriteIds = $this->favorites->favoriteIds($userId);
        $starred = [];
        $friends = [];
        foreach ($accepted as $f) {
            $card = $cards[$f->otherParty($userId)] ?? null;
            if (null === $card) {
                continue;
            }
            if (isset($favoriteIds[$card['userId']])) {
                $starred[] = [...$card, 'isFavorite' => true];
            } else {
                $friends[] = [...$card, 'isFavorite' => false];
            }
        }
        $friends = [...$starred, ...$friends];

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
