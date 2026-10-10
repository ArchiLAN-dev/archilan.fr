<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Query;

use App\PersonalRuns\Application\Command\NudgeRunParticipant;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunNudge;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunNudgeRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Who a player of the run page may nudge (story 43.12): for each player of the session, whether they are idle, when
 * they were last nudged and whether they take nudges at all; plus whether the caller turned them off.
 */
final readonly class RunNudgesQuery
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private RunNudgeRepositoryInterface $nudges,
        private RunPlayerActivityQueryInterface $activity,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Null when the run is unknown or the caller is not in it.
     *
     * @return array{muted: bool, players: list<array{userId: string, lastCheckAt: string|null, idle: bool, muted: bool, lastNudgedAt: string|null, nudgedHoursAgo: int|null, canNudge: bool}>}|null
     */
    public function forRun(string $runId, string $callerId): ?array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return null;
        }
        $sessionId = $run->getSessionId();
        $players = null !== $sessionId ? $this->activity->forSession($sessionId) : new RunPlayersActivity(null, []);
        if (!$run->isOwnedBy($callerId)
            && !$this->participants->findByRunAndUser($runId, $callerId) instanceof RunParticipant
            && !$players->plays($callerId)) {
            return null;
        }

        $nudges = [];
        foreach ($this->nudges->findByRunId($runId) as $nudge) {
            $nudges[$nudge->getRecipientId()] = $nudge;
        }

        $now = $this->clock->now();
        $playing = in_array($run->getStatus(), NudgeRunParticipant::PLAYING_STATUSES, true);
        $rows = [];
        foreach ($players->players as $userId => $player) {
            $nudge = $nudges[$userId] ?? null;
            $idle = $playing && $players->isIdle($userId, $now);
            $rows[] = [
                'userId' => $userId,
                'lastCheckAt' => $player['lastCheckAt']?->format(\DATE_ATOM),
                'idle' => $idle,
                'muted' => $nudge instanceof RunNudge && $nudge->isMuted(),
                'lastNudgedAt' => $nudge?->getLastNudgedAt()?->format(\DATE_ATOM),
                'nudgedHoursAgo' => $nudge?->hoursSinceNudge($now),
                'canNudge' => $idle && $userId !== $callerId && (!$nudge instanceof RunNudge || $nudge->canBeNudged($now)),
            ];
        }

        return [
            'muted' => ($nudges[$callerId] ?? null)?->isMuted() ?? false,
            'players' => $rows,
        ];
    }
}
