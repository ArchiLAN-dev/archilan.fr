<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Support;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;

/**
 * Joining a personal run, shared by the invite link and the invitation by name (story 43.1): the member becomes
 * a participant once, the owner never needs to. Joining a run already launched stays possible; the newcomer then
 * has no slot, as with the link.
 */
final readonly class RunJoiner
{
    public function __construct(private RunParticipantRepositoryInterface $participants)
    {
    }

    /** Saves (and flushes) the participant when one is created, along with any pending change. */
    public function join(Run $run, string $userId, \DateTimeImmutable $now): void
    {
        if ($run->isOwnedBy($userId)) {
            return;
        }

        if (!$this->participants->findByRunAndUser($run->getId(), $userId) instanceof RunParticipant) {
            $this->participants->save(RunParticipant::create($run->getId(), $userId, $now));

            return;
        }

        $this->participants->flush();
    }
}
