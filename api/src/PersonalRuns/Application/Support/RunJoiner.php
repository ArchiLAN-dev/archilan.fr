<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Support;

use App\Community\Application\Support\Notifier;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;

/**
 * Joining a personal run, shared by the invite link and the invitation by name (story 43.1): the member becomes
 * a participant once, the owner never needs to. Joining a run already launched stays possible; the newcomer then
 * has no slot, as with the link.
 *
 * Story 43.28: every road in (link, invitation, open run or listing) tells the owner, through {@see announce()} once
 * the caller's transaction is committed.
 */
final readonly class RunJoiner
{
    public const string NOTIFICATION_TYPE = 'run_joined';

    public function __construct(
        private RunParticipantRepositoryInterface $participants,
        private Notifier $notifier,
        private UserRepositoryInterface $users,
    ) {
    }

    /**
     * Saves (and flushes) the participant when one is created, along with any pending change.
     *
     * @return bool whether the member just became a participant
     */
    public function join(Run $run, string $userId, \DateTimeImmutable $now): bool
    {
        if ($run->isOwnedBy($userId)) {
            return false;
        }

        if (!$this->participants->findByRunAndUser($run->getId(), $userId) instanceof RunParticipant) {
            $this->participants->save(RunParticipant::create($run->getId(), $userId, $now));

            return true;
        }

        $this->participants->flush();

        return false;
    }

    /** Tells the owner a member just joined; called after the commit, for a join() that returned true. */
    public function announce(Run $run, string $userId): void
    {
        $member = $this->users->findById($userId);
        $this->notifier->notify($run->getOwnerId(), self::NOTIFICATION_TYPE, [
            'fromUserId' => $userId,
            'joinerName' => $member instanceof User ? $member->getDisplayName() : null,
            'runId' => $run->getId(),
            'runTitle' => $run->getTitle(),
        ]);
    }
}
