<?php

declare(strict_types=1);

namespace App\Community\Application\Service;

use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Domain\Entity\FriendLink;
use App\Community\Domain\Repository\BlockRepositoryInterface;
use App\Community\Domain\Repository\FriendLinkRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * « Mon lien d'ami » (story 43.3): a personal link, shown as a QR code at a LAN, that adds its owner as a friend.
 * An unknown code, a regenerated one, a block either way or an unlisted owner all read the same: an invalid link,
 * so the page never tells who blocked whom. The friend request itself is the one of story 30.7.
 */
final readonly class FriendLinkService
{
    /** No 0/o, 1/l/i: a code read out loud or typed from a screen stays unambiguous. */
    private const string ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    private const int CODE_LENGTH = 12;

    public function __construct(
        private FriendLinkRepositoryInterface $links,
        private BlockRepositoryInterface $blocks,
        private CommunityUserDirectoryQueryInterface $directory,
        private FriendshipService $friendships,
        private ClockInterface $clock,
    ) {
    }

    /** The member's code, made on first ask. */
    public function codeFor(string $userId): string
    {
        $link = $this->links->findByUserId($userId);
        if ($link instanceof FriendLink) {
            return $link->getCode();
        }

        try {
            $link = FriendLink::create($userId, self::newCode(), $this->clock->now());
            $this->links->save($link);

            return $link->getCode();
        } catch (UniqueConstraintViolationException) {
            // A concurrent first ask made it already.
            return $this->links->findByUserId($userId)?->getCode() ?? throw new \LogicException('Friend link vanished.');
        }
    }

    /** A new code; the old link stops working. */
    public function regenerate(string $userId): string
    {
        $link = $this->links->findByUserId($userId);
        if (!$link instanceof FriendLink) {
            return $this->codeFor($userId);
        }

        $link->regenerate(self::newCode(), $this->clock->now());
        $this->links->save($link);

        return $link->getCode();
    }

    /**
     * The link's owner as the visitor sees them, or null for an invalid link.
     *
     * @return array{member: array{userId: string, slug: string, displayName: string|null, avatarUrl: string|null, avatarAnimatedUrl: string|null, avatarFraming: array{x: int, y: int, zoom: int}|null, avatarFrame: string|null, nameStyle: string|null, title: array{label: string, rarity: string, icon: string|null, access: string}|null}, relationship: array{state: string, friendshipId: string|null}}|null
     */
    public function open(string $viewerId, string $code): ?array
    {
        $ownerId = $this->ownerOf($viewerId, $code);
        if (null === $ownerId) {
            return null;
        }
        $member = $this->directory->cards([$ownerId])[$ownerId] ?? null;
        if (null === $member) {
            return null;
        }

        return ['member' => $member, 'relationship' => $this->friendships->relationship($viewerId, $ownerId)];
    }

    /**
     * « Ajouter en ami » from the link: a friend request, or a mutual accept when the owner already asked.
     *
     * @return array{state: string, friendshipId: string|null}|null null for an invalid link
     */
    public function add(string $viewerId, string $code): ?array
    {
        $ownerId = $this->ownerOf($viewerId, $code);
        if (null === $ownerId || [] === $this->directory->cards([$ownerId])) {
            return null;
        }

        $this->friendships->requestFriend($viewerId, $ownerId);

        return $this->friendships->relationship($viewerId, $ownerId);
    }

    private function ownerOf(string $viewerId, string $code): ?string
    {
        $link = $this->links->findByCode(strtolower(trim($code)));
        if (!$link instanceof FriendLink) {
            return null;
        }
        $ownerId = $link->getUserId();
        if ($ownerId !== $viewerId && $this->blocks->existsEitherWay($viewerId, $ownerId)) {
            return null;
        }

        return $ownerId;
    }

    private static function newCode(): string
    {
        $code = '';
        $last = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::CODE_LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return $code;
    }
}
