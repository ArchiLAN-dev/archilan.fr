<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

use App\Community\Application\Support\Notifier;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Application\Query\RunInviteFriendsQueryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunInvitation;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunInvitationRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The owner of a personal run invites friends by name (story 43.1). A friend already in the run, already invited
 * and not yet answering, or who declined less than a day ago is skipped; so is anyone who is not an accepted
 * friend, or with a block either way. A run sends at most MAX_PER_DAY invitations over 24 hours. Each invitee is
 * notified once the invitations are saved - a notification that fails never undoes them.
 */
final readonly class InviteFriendsToRun
{
    public const string NOTIFICATION_TYPE = 'run_invitation';

    public const int MAX_PER_DAY = 20;

    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private RunInvitationRepositoryInterface $invitations,
        private RunInviteFriendsQueryInterface $friends,
        private UserRepositoryInterface $users,
        private Notifier $notifier,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $userIds
     */
    public function invite(string $runId, string $callerId, array $userIds): InviteFriendsToRunResult
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return new InviteFriendsToRunResult(InviteFriendsToRunOutcome::NotFound);
        }
        if (!$run->isOwnedBy($callerId)) {
            return new InviteFriendsToRunResult(InviteFriendsToRunOutcome::Forbidden);
        }
        if ($run->isTerminal()) {
            return new InviteFriendsToRunResult(InviteFriendsToRunOutcome::RunEnded);
        }

        $now = $this->clock->now();
        $toInvite = [];
        $skipped = [];
        foreach (array_values(array_unique($userIds)) as $userId) {
            if ($this->isInvitable($run, $userId, $now)) {
                $toInvite[] = $userId;
            } else {
                $skipped[] = $userId;
            }
        }

        if ([] !== $toInvite && $this->invitations->countSentSince($run->getId(), $now->modify('-24 hours')) + \count($toInvite) > self::MAX_PER_DAY) {
            return new InviteFriendsToRunResult(InviteFriendsToRunOutcome::DailyLimit);
        }

        $sent = [];
        foreach ($toInvite as $userId) {
            $invitation = $this->invitations->findByRunAndInvitee($run->getId(), $userId);
            if ($invitation instanceof RunInvitation) {
                $invitation->resend($callerId, $now);
            } else {
                $invitation = RunInvitation::send($run->getId(), $userId, $callerId, $now);
                $this->invitations->save($invitation);
            }
            $sent[] = $invitation;
        }
        $this->invitations->flush();

        $owner = $this->users->findById($callerId);
        foreach ($sent as $invitation) {
            $this->notifier->notify($invitation->getInviteeId(), self::NOTIFICATION_TYPE, [
                'fromUserId' => $callerId,
                'inviterName' => $owner instanceof User ? $owner->getDisplayName() : null,
                'invitationId' => $invitation->getId(),
                'runId' => $run->getId(),
                'runTitle' => $run->getTitle(),
            ]);
        }

        return new InviteFriendsToRunResult(InviteFriendsToRunOutcome::Invited, $toInvite, $skipped);
    }

    private function isInvitable(Run $run, string $userId, \DateTimeImmutable $now): bool
    {
        if ($run->isOwnedBy($userId)
            || $this->participants->findByRunAndUser($run->getId(), $userId) instanceof RunParticipant
            || !$this->friends->canInvite($run->getOwnerId(), $userId)) {
            return false;
        }
        $existing = $this->invitations->findByRunAndInvitee($run->getId(), $userId);

        return !$existing instanceof RunInvitation || $existing->canBeSentAgain($now);
    }
}
