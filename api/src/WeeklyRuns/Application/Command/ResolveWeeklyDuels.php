<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Command;

use App\Community\Application\Command\RecordActivity;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Support\Notifier;
use App\Community\Domain\Entity\ActivityEntry;
use App\Sessions\Application\Port\AchievementRecomputeTriggerInterface;
use App\WeeklyRuns\Application\Query\WeeklyDuelContextQueryInterface;
use App\WeeklyRuns\Application\Query\WeeklyRunFriendEntriesQueryInterface;
use App\WeeklyRuns\Application\Support\WeeklyDuelBlockRule;
use App\WeeklyRuns\Application\Support\WeeklyStanding;
use App\WeeklyRuns\Domain\Entity\WeeklyDuel;
use App\WeeklyRuns\Domain\Entity\WeeklyDuelParticipant;
use App\WeeklyRuns\Domain\Repository\WeeklyDuelRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Settles the duels of a weekly run once it ended (story 43.15): the best time at the goal among those who accepted
 * wins; nobody at the goal, or two best times alike (story 43.18), leaves the duel without a winner. Once the duels are saved, each member who accepted
 * hears the result, and a won duel goes to the activity feed, seen by the winner's friends. A duel nobody accepted
 * is closed quietly. A notification or an activity entry that fails never undoes the result.
 */
final readonly class ResolveWeeklyDuels
{
    public const string NOTIFICATION_TYPE = 'weekly_duel_result';

    public function __construct(
        private WeeklyDuelRepositoryInterface $duels,
        private WeeklyDuelContextQueryInterface $context,
        private WeeklyRunFriendEntriesQueryInterface $entries,
        private CommunityUserDirectoryQueryInterface $directory,
        private Notifier $notifier,
        private RecordActivity $recordActivity,
        private AchievementRecomputeTriggerInterface $achievements,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function forRun(string $weeklyRunId): void
    {
        $duels = $this->duels->unresolvedForRun($weeklyRunId);
        if ([] === $duels) {
            return;
        }

        $now = $this->clock->now();
        $participants = $this->duels->participantsByDuel(array_map(static fn (WeeklyDuel $d): string => $d->getId(), $duels));
        $results = [];
        foreach ($duels as $duel) {
            $members = $participants[$duel->getId()] ?? [];
            WeeklyDuelBlockRule::apply($duel, $members, $this->context->blocksAmong(WeeklyDuelBlockRule::activeUserIds($members)), $now);
            $accepted = array_values(array_map(
                static fn (WeeklyDuelParticipant $p): string => $p->getUserId(),
                array_filter($members, static fn (WeeklyDuelParticipant $p): bool => $p->isAccepted()),
            ));
            $ranked = \count($accepted) < 2 ? [] : WeeklyStanding::rank($this->entries->entriesOf($weeklyRunId, $accepted));
            $leaders = self::leaders($ranked);
            // Story 43.18: two best times alike at the goal crown nobody.
            $winnerId = 1 === \count($leaders) ? $leaders[0] : null;
            $duel->resolve($winnerId, $now);
            if (\count($accepted) >= 2) {
                $results[] = ['duel' => $duel, 'accepted' => $accepted, 'ranked' => $ranked, 'leaders' => $leaders];
            }
        }
        $this->duels->flush();

        $gameName = $this->context->runs([$weeklyRunId])[$weeklyRunId]['gameName'] ?? null;
        $winners = [];
        foreach ($results as $result) {
            try {
                $this->announce($result['duel'], $result['accepted'], $result['ranked'], $result['leaders'], $gameName, $now);
            } catch (\Throwable $e) {
                $this->logger->warning('weekly_duel.announce_failed', ['duelId' => $result['duel']->getId(), 'error' => $e->getMessage()]);
            }
            $winnerId = $result['duel']->getWinnerId();
            if (null !== $winnerId) {
                $winners[] = $winnerId;
            }
        }

        // Story 43.18: a won duel may unlock a social achievement (story 43.16), notified now rather than by the
        // silent hourly backstop.
        if ([] !== $winners) {
            try {
                $this->achievements->recomputeForUsers(array_values(array_unique($winners)));
            } catch (\Throwable $e) {
                $this->logger->warning('weekly_duel.achievements_failed', ['weeklyRunId' => $weeklyRunId, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * The members at the goal with the best time: one is the winner, several are tied, none means nobody made it.
     *
     * @param array<string, array{status: string, completionTimeSeconds: int|null}> $ranked
     *
     * @return list<string>
     */
    private static function leaders(array $ranked): array
    {
        $first = array_key_first($ranked);
        if (null === $first || 'goal' !== $ranked[$first]['status']) {
            return [];
        }
        $best = $ranked[$first]['completionTimeSeconds'];

        return array_keys(array_filter($ranked, static fn (array $standing): bool => 'goal' === $standing['status'] && $standing['completionTimeSeconds'] === $best));
    }

    /**
     * @param list<string>                                                          $accepted
     * @param array<string, array{status: string, completionTimeSeconds: int|null}> $ranked
     * @param list<string>                                                          $leaders
     */
    private function announce(WeeklyDuel $duel, array $accepted, array $ranked, array $leaders, ?string $gameName, \DateTimeImmutable $now): void
    {
        $names = $this->directory->namesFor($accepted);
        $winnerId = 1 === \count($leaders) ? $leaders[0] : null;
        $order = array_keys($ranked);
        $runnerUp = $order[1] ?? null;
        $leaderTime = [] !== $leaders ? $ranked[$leaders[0]]['completionTimeSeconds'] ?? null : null;
        $winnerTime = null !== $winnerId ? $leaderTime : null;

        foreach ($accepted as $userId) {
            $time = $ranked[$userId]['completionTimeSeconds'] ?? null;
            if ([] === $leaders) {
                $outcome = 'none';
                $opponentId = null;
                $margin = null;
            } elseif (null === $winnerId && in_array($userId, $leaders, true)) {
                // Story 43.18: tied at the top - the result names another of the tied members.
                $outcome = 'tie';
                $opponentId = array_values(array_filter($leaders, static fn (string $id): bool => $id !== $userId))[0] ?? null;
                $margin = null;
            } elseif (null === $winnerId) {
                $outcome = 'lost';
                $opponentId = $leaders[0];
                $margin = null !== $time && null !== $leaderTime ? $time - $leaderTime : null;
            } elseif ($userId === $winnerId) {
                $outcome = 'won';
                $opponentId = $runnerUp;
                $runnerUpTime = null !== $runnerUp ? ($ranked[$runnerUp]['completionTimeSeconds'] ?? null) : null;
                $margin = null !== $runnerUpTime && null !== $winnerTime ? $runnerUpTime - $winnerTime : null;
            } else {
                $outcome = 'lost';
                $opponentId = $winnerId;
                $margin = null !== $time && null !== $winnerTime ? $time - $winnerTime : null;
            }
            $this->notifier->notify($userId, self::NOTIFICATION_TYPE, [
                'fromUserId' => $opponentId,
                'duelId' => $duel->getId(),
                'weeklyRunId' => $duel->getWeeklyRunId(),
                'gameName' => $gameName,
                'outcome' => $outcome,
                'opponentName' => null !== $opponentId ? ($names[$opponentId] ?? null) : null,
                'marginSeconds' => $margin,
                'players' => \count($accepted),
            ]);
        }

        if (null !== $winnerId) {
            $runnerUpTime = null !== $runnerUp ? ($ranked[$runnerUp]['completionTimeSeconds'] ?? null) : null;
            $this->recordActivity->record($winnerId, ActivityEntry::TYPE_WEEKLY_DUEL, 'weekly_duel:'.$duel->getId(), $now, [
                'game' => $gameName,
                'withUserId' => $runnerUp,
                'marginSeconds' => null !== $runnerUpTime && null !== $winnerTime ? $runnerUpTime - $winnerTime : null,
                'players' => \count($accepted),
            ]);
        }
    }
}
