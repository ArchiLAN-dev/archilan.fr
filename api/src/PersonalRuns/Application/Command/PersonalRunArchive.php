<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunArchive;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunArchiveRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use Psr\Clock\ClockInterface;

/**
 * Archive a personal run in one member's list, or bring it back (story 16.21). Personal: the owner or a
 * participant tidies their own list, the others still see the run; an admin acts for a member through the
 * same rules. Both calls are idempotent - archiving twice, or bringing back a run that was not archived,
 * changes nothing.
 */
final readonly class PersonalRunArchive
{
    /** A run that holds a server - or is about to - must be stopped before it is put away. */
    private const array LIVE_STATUSES = [
        Run::STATUS_STARTING,
        Run::STATUS_ACTIVE,
        Run::STATUS_STOPPING,
        Run::STATUS_IDLE,
        Run::STATUS_RESTARTING,
    ];

    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private RunArchiveRepositoryInterface $archives,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws NotFoundException  when the run does not exist
     * @throws ForbiddenException when the member is neither its owner nor a participant
     * @throws ConflictException  when the run still holds a party
     */
    public function archive(string $runId, string $userId): void
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            throw new NotFoundException('Run introuvable.');
        }
        if (!$run->isOwnedBy($userId) && !$this->participants->findByRunAndUser($runId, $userId) instanceof RunParticipant) {
            throw new ForbiddenException('Cette partie ne fait pas partie de tes parties.');
        }
        if (in_array($run->getStatus(), self::LIVE_STATUSES, true)) {
            throw new ConflictException('Arrête la partie avant de l\'archiver.', 'run_live');
        }
        if ($this->archives->find($runId, $userId) instanceof RunArchive) {
            return;
        }

        $this->archives->save(RunArchive::create($runId, $userId, $this->clock->now()));
    }

    /**
     * Only removes the member's own archive line: no participation check, a member who left the run can still
     * get it back in their list.
     *
     * @throws NotFoundException when the run does not exist
     */
    public function unarchive(string $runId, string $userId): void
    {
        if (!$this->runs->findById($runId) instanceof Run) {
            throw new NotFoundException('Run introuvable.');
        }

        $archive = $this->archives->find($runId, $userId);
        if ($archive instanceof RunArchive) {
            $this->archives->remove($archive);
        }
    }
}
