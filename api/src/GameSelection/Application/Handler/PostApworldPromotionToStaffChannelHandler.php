<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Handler;

use App\GameSelection\Application\Exception\StaffAlertDeliveryException;
use App\GameSelection\Application\Message\PostApworldPromotionToStaffChannelJob;
use App\GameSelection\Application\Port\StaffAlertChannelInterface;
use App\GameSelection\Application\Support\StaffAlertFactory;
use App\GameSelection\Domain\Repository\ApworldCandidateRepositoryInterface;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Announces a promotion on the staff channel (story 38.6 AC 11). Never throws: the switch is already
 * committed, a Discord outage only loses the message, and says so in the logs.
 */
#[AsMessageHandler]
final readonly class PostApworldPromotionToStaffChannelHandler
{
    public function __construct(
        private ApworldCandidateRepositoryInterface $candidates,
        private GameRepositoryInterface $games,
        private UserRepositoryInterface $users,
        private StaffAlertFactory $alerts,
        private StaffAlertChannelInterface $channel,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PostApworldPromotionToStaffChannelJob $job): void
    {
        $candidate = $this->candidates->findById($job->candidateId);
        if (null === $candidate) {
            return;
        }
        $game = $this->games->findById($candidate->getGameId());

        $forcedBy = $candidate->getForcedBy();
        $forcedByName = null === $forcedBy ? null : ($this->users->findById($forcedBy)?->getDisplayName() ?? 'Un admin');
        $approvedBy = $candidate->getApprovedBy();
        $approvedByName = null === $approvedBy ? null : ($this->users->findById($approvedBy)?->getDisplayName() ?? 'Un admin');

        // The game's release link is the latest known release: only worth giving when it is the one
        // that was just promoted.
        $releaseUrl = null !== $game && null !== $candidate->getVersionTag() && $game->getApworldLatestVersion() === $candidate->getVersionTag()
            ? $game->getApworldReleaseUrl()
            : null;

        try {
            $this->channel->post($this->alerts->promoted(
                $game?->getName() ?? $candidate->getGameId(),
                $candidate->getGameId(),
                $job->previousVersion,
                $candidate->getVersionTag(),
                $candidate->getOrigin(),
                $forcedByName,
                $releaseUrl,
                $approvedByName,
            ));
        } catch (StaffAlertDeliveryException $e) {
            $this->logger->warning('apworld_candidates.promotion_not_posted', [
                'candidateId' => $candidate->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
