<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Domain\Repository\FriendshipRepositoryInterface;

/**
 * « Entre nous » (story 43.10): in a session's recap, what the viewer exchanged with each other slot, friends first,
 * and the BKs another player got them out of. Only for a participant: nothing on a public recap seen by a third
 * party. Computed on read, one aggregated feed query; the recap is not rebuilt for it.
 *
 * A slot several people play is the slot's, not a person's: its players are listed together (« X et Y »).
 */
final readonly class RecapExchangesQuery
{
    /**
     * How long before a BK release the item that ended it may have arrived: the bridge recomputes reachability on
     * every received item, so the release follows the item within seconds; two minutes leave room for a slow push.
     */
    public const int UNBLOCK_WINDOW_SECONDS = 120;

    public function __construct(
        private RecapExchangesQueryInterface $exchanges,
        private FriendshipRepositoryInterface $friendships,
        private CommunityUserDirectoryQueryInterface $directory,
    ) {
    }

    /**
     * @return array{exchanges: list<array<string, mixed>>, unblocks: list<array<string, mixed>>}|null null for someone
     *                                                                                                 who did not play the session
     */
    public function forViewer(string $sessionId, string $viewerId): ?array
    {
        $playersBySlot = [];
        $mine = [];
        foreach ($this->exchanges->playersBySlot($sessionId) as $slot) {
            $playersBySlot[] = $slot;
            if (in_array($viewerId, $slot['userIds'], true)) {
                $mine[] = $slot['slotName'];
            }
        }
        if ([] === $mine) {
            return null;
        }

        $friendIds = [];
        foreach ($this->friendships->findAccepted($viewerId) as $friendship) {
            $friendIds[$friendship->otherParty($viewerId)] = true;
        }
        $others = array_values(array_unique(array_merge([], ...array_column($playersBySlot, 'userIds'))));
        $cards = $this->directory->cards(array_values(array_filter($others, static fn (string $id): bool => $id !== $viewerId)));

        $players = function (string $slotName) use ($playersBySlot, $viewerId, $cards, $friendIds): array {
            $list = [];
            foreach ($playersBySlot as $slot) {
                if ($slot['slotName'] !== $slotName) {
                    continue;
                }
                foreach ($slot['userIds'] as $userId) {
                    if ($userId !== $viewerId && isset($cards[$userId])) {
                        $list[] = [...$cards[$userId], 'isFriend' => isset($friendIds[$userId])];
                    }
                }
            }

            return $list;
        };

        $rows = [];
        foreach ($this->exchanges->exchanges($sessionId, $mine) as $counts) {
            $slotPlayers = $players($counts['slotName']);
            $rows[] = [
                ...$counts,
                'players' => $slotPlayers,
                'hasFriend' => [] !== array_filter($slotPlayers, static fn (array $p): bool => $p['isFriend']),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['hasFriend'], $b['sent'] + $b['received'], $a['slotName']]
            <=> [$a['hasFriend'], $a['sent'] + $a['received'], $b['slotName']]);

        $unblocks = [];
        foreach ($this->exchanges->unblockingItems($sessionId, $mine, self::UNBLOCK_WINDOW_SECONDS) as $moment) {
            $unblocks[] = [...$moment, 'senders' => $players($moment['senderName'])];
        }

        return ['exchanges' => $rows, 'unblocks' => $unblocks];
    }
}
