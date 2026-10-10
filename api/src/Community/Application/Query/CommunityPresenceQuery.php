<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Community\Domain\Enum\PresenceSlotState;
use App\Community\Domain\Enum\PresenceVisibility;
use App\Community\Domain\Service\AudiencePolicy;
use App\Membership\Application\Query\ActiveMembershipQueryInterface;

/**
 * Applies each member's presence visibility to the live read (story 43.6), server-side and for every surface:
 * profile, feed, directory, hub, run participants. The viewer's tier towards a member is resolved per row: self,
 * friend (from the read), then member (live membership) or authenticated or anonymous. The membership is only
 * asked when a row needs it, once per call.
 */
final readonly class CommunityPresenceQuery implements CommunityPresenceQueryInterface
{
    public function __construct(
        private LivePresenceQueryInterface $live,
        private ActiveMembershipQueryInterface $memberships,
    ) {
    }

    public function playing(array $userIds, ?string $viewerId): array
    {
        $strangerTier = null;
        $visible = [];
        foreach ($this->live->playing($userIds, $viewerId) as $userId => $row) {
            if ($this->shows($row, $userId, $viewerId, $strangerTier)) {
                $visible[$userId] = $row;
            }
        }

        // Story 43.7: one snapshot read for every session shown, and only for the presences the viewer may see.
        $snapshots = $this->live->snapshotSlots(array_values(array_unique(array_column($visible, 'sessionId'))));
        $playing = [];
        foreach ($visible as $userId => $row) {
            $slot = null === $row['slotName'] ? null : ($snapshots[$row['sessionId']][$row['slotName']] ?? null);
            ['state' => $state, 'percent' => $percent] = PresenceSlotState::of($slot, $row['goalReached'], $row['tracked']);
            $playing[$userId] = ['sessionId' => $row['sessionId'], 'game' => $row['game'], 'slotState' => $state->value, 'progressPercent' => $percent];
        }

        return $playing;
    }

    public function recentlyPlayed(array $userIds, ?string $viewerId, \DateTimeImmutable $since): array
    {
        $strangerTier = null;
        $finished = [];
        foreach ($this->live->recentlyFinished($userIds, $viewerId, $since) as $userId => $row) {
            if ($this->shows($row, $userId, $viewerId, $strangerTier)) {
                $finished[$userId] = ['sessionId' => $row['sessionId'], 'game' => $row['game'], 'finishedAt' => $row['finishedAt']];
            }
        }

        return $finished;
    }

    public function playingNow(int $limit, ?string $viewerId): array
    {
        if ($limit <= 0) {
            return [];
        }

        $strangerTier = null;
        $result = [];
        foreach ($this->live->playingNow($viewerId) as $row) {
            if (!$this->shows($row, $row['userId'], $viewerId, $strangerTier)) {
                continue;
            }
            $result[] = ['userId' => $row['userId'], 'sessionId' => $row['sessionId'], 'game' => $row['game']];
            if (\count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param array{visibility: string, friend: bool} $row
     * @param string|null                             $strangerTier the viewer's tier towards a stranger, resolved on
     *                                                              first need and kept for the rest of the call
     */
    private function shows(array $row, string $userId, ?string $viewerId, ?string &$strangerTier): bool
    {
        $visibility = PresenceVisibility::tryFrom($row['visibility']) ?? PresenceVisibility::DEFAULT;
        if (PresenceVisibility::Everyone === $visibility || $viewerId === $userId) {
            return true;
        }
        if ($row['friend']) {
            return $visibility->shows(AudiencePolicy::TIER_FRIEND);
        }
        $strangerTier ??= $this->strangerTier($viewerId);

        return $visibility->shows($strangerTier);
    }

    private function strangerTier(?string $viewerId): string
    {
        if (null === $viewerId) {
            return AudiencePolicy::TIER_ANONYMOUS;
        }

        return $this->memberships->hasActiveMembership($viewerId) ? AudiencePolicy::TIER_MEMBER : AudiencePolicy::TIER_AUTHENTICATED;
    }
}
