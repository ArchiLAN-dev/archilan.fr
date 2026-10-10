<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

use App\Community\Application\Support\Notifier;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Application\Query\RunInviteFriendsQueryInterface;
use App\PersonalRuns\Application\Support\RunJoiner;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunInvitation;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunInvitationRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * A friend joins a draft run its owner opened to friends (story 43.14), with the invite link's logic
 * ({@see RunJoiner}). A pending invitation by name for the same run counts as accepted. The owner hears of each
 * arrival in the bell, once the participant is saved.
 */
final readonly class JoinOpenRun
{
    public const string NOTIFICATION_TYPE = 'run_joined';

    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private RunInvitationRepositoryInterface $invitations,
        private RunInviteFriendsQueryInterface $friends,
        private RunJoiner $joiner,
        private UserRepositoryInterface $users,
        private Notifier $notifier,
        private ClockInterface $clock,
    ) {
    }

    public function join(string $runId, string $userId): JoinOpenRunOutcome
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return JoinOpenRunOutcome::NotFound;
        }
        if ($run->isOwnedBy($userId) || $this->participants->findByRunAndUser($runId, $userId) instanceof RunParticipant) {
            return JoinOpenRunOutcome::Joined;
        }
        if (!$run->isOpenToFriends() || !$this->friends->canInvite($run->getOwnerId(), $userId)) {
            return JoinOpenRunOutcome::NotFound;
        }
        $joined = \count(array_filter(
            $this->participants->findByRunId($runId),
            static fn (RunParticipant $p): bool => !$run->isOwnedBy($p->getUserId()),
        ));
        if ($run->isFull($joined)) {
            return JoinOpenRunOutcome::Full;
        }

        $now = $this->clock->now();
        $invitation = $this->invitations->findByRunAndInvitee($runId, $userId);
        if ($invitation instanceof RunInvitation && $invitation->isPending()) {
            $invitation->accept($now);
        }
        // One flush: the participant and the accepted invitation land together.
        $this->joiner->join($run, $userId, $now);

        $member = $this->users->findById($userId);
        $this->notifier->notify($run->getOwnerId(), self::NOTIFICATION_TYPE, [
            'fromUserId' => $userId,
            'joinerName' => $member instanceof User ? $member->getDisplayName() : null,
            'runId' => $run->getId(),
            'runTitle' => $run->getTitle(),
        ]);

        return JoinOpenRunOutcome::Joined;
    }
}
