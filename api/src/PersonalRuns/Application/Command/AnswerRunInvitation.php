<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

use App\PersonalRuns\Application\Query\RunInviteFriendsQueryInterface;
use App\PersonalRuns\Application\Support\RunJoiner;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunInvitation;
use App\PersonalRuns\Domain\Repository\RunInvitationRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The invitee answers an invitation by name (story 43.1). Joining is the invite link's logic ({@see RunJoiner});
 * it holds only while the run takes players and the two are still friends without a block - otherwise the
 * invitation is closed and the answer says why.
 */
final readonly class AnswerRunInvitation
{
    public function __construct(
        private RunInvitationRepositoryInterface $invitations,
        private RunRepositoryInterface $runs,
        private RunInviteFriendsQueryInterface $friends,
        private RunJoiner $joiner,
        private ClockInterface $clock,
    ) {
    }

    public function accept(string $invitationId, string $userId): AnswerRunInvitationResult
    {
        $invitation = $this->invitations->findById($invitationId);
        if (!$invitation instanceof RunInvitation || $invitation->getInviteeId() !== $userId) {
            return new AnswerRunInvitationResult(AnswerRunInvitationOutcome::NotFound);
        }
        $runId = $invitation->getRunId();
        if (RunInvitation::ACCEPTED === $invitation->getStatus()) {
            return new AnswerRunInvitationResult(AnswerRunInvitationOutcome::Joined, $runId);
        }
        if (!$invitation->isPending()) {
            return new AnswerRunInvitationResult(AnswerRunInvitationOutcome::NoLongerOpen, $runId);
        }

        $now = $this->clock->now();
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run || $run->isTerminal()) {
            $invitation->close($now);
            $this->invitations->flush();

            return new AnswerRunInvitationResult(AnswerRunInvitationOutcome::RunEnded, $runId);
        }
        if (!$this->friends->canInvite($invitation->getInviterId(), $userId)) {
            $invitation->close($now);
            $this->invitations->flush();

            return new AnswerRunInvitationResult(AnswerRunInvitationOutcome::NoLongerFriends, $runId);
        }

        $invitation->accept($now);
        // One flush: the participant and the accepted invitation land together.
        $this->joiner->join($run, $userId, $now);

        return new AnswerRunInvitationResult(AnswerRunInvitationOutcome::Joined, $runId);
    }

    public function decline(string $invitationId, string $userId): AnswerRunInvitationResult
    {
        $invitation = $this->invitations->findById($invitationId);
        if (!$invitation instanceof RunInvitation || $invitation->getInviteeId() !== $userId) {
            return new AnswerRunInvitationResult(AnswerRunInvitationOutcome::NotFound);
        }
        if (!$invitation->isPending()) {
            return new AnswerRunInvitationResult(AnswerRunInvitationOutcome::NoLongerOpen, $invitation->getRunId());
        }

        $invitation->decline($this->clock->now());
        $this->invitations->flush();

        return new AnswerRunInvitationResult(AnswerRunInvitationOutcome::Declined, $invitation->getRunId());
    }
}
