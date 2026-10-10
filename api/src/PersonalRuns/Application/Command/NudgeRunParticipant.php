<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

use App\Community\Application\Support\Notifier;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Application\Query\RunPlayerActivityQueryInterface;
use App\PersonalRuns\Application\Query\RunPlayersActivity;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunNudge;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunNudgeRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * A player of a personal run nudges another who has not played for two days (story 43.12). Only on an active or
 * idle run, towards a player with slots still to play; at most once a day per player whoever sends it, never
 * towards a player who turned nudges off for this run. The notification goes out once the nudge is saved.
 */
final readonly class NudgeRunParticipant
{
    public const string NOTIFICATION_TYPE = 'run_nudge';

    /** @var list<string> */
    public const array PLAYING_STATUSES = [Run::STATUS_ACTIVE, Run::STATUS_IDLE];

    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private RunNudgeRepositoryInterface $nudges,
        private RunPlayerActivityQueryInterface $activity,
        private UserRepositoryInterface $users,
        private Notifier $notifier,
        private ClockInterface $clock,
    ) {
    }

    public function nudge(string $runId, string $callerId, string $recipientId): NudgeRunParticipantResult
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return new NudgeRunParticipantResult(NudgeRunParticipantOutcome::NotFound);
        }
        $sessionId = $run->getSessionId();
        $players = null !== $sessionId ? $this->activity->forSession($sessionId) : null;
        if (!$this->isInRun($run, $callerId, $players)) {
            return new NudgeRunParticipantResult(NudgeRunParticipantOutcome::Forbidden);
        }
        if (null === $players || !in_array($run->getStatus(), self::PLAYING_STATUSES, true)) {
            return new NudgeRunParticipantResult(NudgeRunParticipantOutcome::RunNotPlaying);
        }

        $now = $this->clock->now();
        if ($recipientId === $callerId || !$players->isIdle($recipientId, $now) || $this->activity->blockedBetween($callerId, $recipientId)) {
            return new NudgeRunParticipantResult(NudgeRunParticipantOutcome::NotIdle);
        }

        $existing = $this->nudges->find($runId, $recipientId);
        $nudge = $existing ?? RunNudge::open($runId, $recipientId, $now);
        if ($nudge->isMuted()) {
            return new NudgeRunParticipantResult(NudgeRunParticipantOutcome::Muted);
        }
        if ($nudge->isCoolingDown($now)) {
            return new NudgeRunParticipantResult(NudgeRunParticipantOutcome::AlreadyNudged, $nudge->getLastNudgedAt(), $nudge->hoursSinceNudge($now));
        }

        if ($existing instanceof RunNudge) {
            // Story 43.19: one conditional write, so two senders at the same instant cannot both go through.
            if (!$this->nudges->claimNudge($runId, $recipientId, $callerId, $now, RunNudge::cooldownStart($now))) {
                return new NudgeRunParticipantResult(NudgeRunParticipantOutcome::AlreadyNudged, $now, 1);
            }
        } else {
            $nudge->nudge($callerId, $now);
            try {
                $this->nudges->save($nudge);
            } catch (UniqueConstraintViolationException) {
                // Another player nudged them at the same instant: theirs is the one of the day.
                return new NudgeRunParticipantResult(NudgeRunParticipantOutcome::AlreadyNudged, $now, 1);
            }
        }

        $sender = $this->users->findById($callerId);
        $this->notifier->notify($recipientId, self::NOTIFICATION_TYPE, [
            'fromUserId' => $callerId,
            'senderName' => $sender instanceof User ? $sender->getDisplayName() : null,
            'runId' => $run->getId(),
            'runTitle' => $run->getTitle(),
        ]);

        return new NudgeRunParticipantResult(NudgeRunParticipantOutcome::Nudged, $now);
    }

    /**
     * The caller turns nudges off (or back on) for this run. False when the run is unknown or the caller does not
     * play in it.
     */
    public function mute(string $runId, string $callerId, bool $muted): bool
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return false;
        }
        $sessionId = $run->getSessionId();
        if (!$this->isInRun($run, $callerId, null !== $sessionId ? $this->activity->forSession($sessionId) : null)) {
            return false;
        }

        $now = $this->clock->now();
        $nudge = $this->nudges->find($runId, $callerId) ?? RunNudge::open($runId, $callerId, $now);
        $nudge->mute($muted, $now);
        try {
            $this->nudges->save($nudge);
        } catch (UniqueConstraintViolationException) {
            // A nudge landed at the same instant - the member can choose again.
        }

        return true;
    }

    /** The owner, a participant, or someone who plays one of the session's slots. */
    private function isInRun(Run $run, string $userId, ?RunPlayersActivity $players): bool
    {
        return $run->isOwnedBy($userId)
            || $this->participants->findByRunAndUser($run->getId(), $userId) instanceof RunParticipant
            || (null !== $players && $players->plays($userId));
    }
}
