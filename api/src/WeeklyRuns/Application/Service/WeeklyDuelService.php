<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Service;

use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Query\FriendCircleQuery;
use App\Community\Application\Support\Notifier;
use App\WeeklyRuns\Application\Query\WeeklyDuelContextQueryInterface;
use App\WeeklyRuns\Application\Query\WeeklyRunFriendEntriesQueryInterface;
use App\WeeklyRuns\Application\Support\WeeklyDuelBlockRule;
use App\WeeklyRuns\Application\Support\WeeklyStanding;
use App\WeeklyRuns\Domain\Entity\WeeklyDuel;
use App\WeeklyRuns\Domain\Entity\WeeklyDuelParticipant;
use App\WeeklyRuns\Domain\Repository\WeeklyDuelRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Duels between friends on a weekly run (story 43.15): challenge up to MAX_OPPONENTS friends, answer a challenge,
 * and the duels a member is in with their mini-ranking until the weekly run ends. Accepting does not register the
 * member to the weekly run; the page offers it. The challenged friends hear of it once the duel is saved.
 */
final readonly class WeeklyDuelService
{
    public const string NOTIFICATION_TYPE = 'weekly_duel';

    public const string OK = 'ok';
    public const string NOT_FOUND = 'not_found';
    public const string RUN_ENDED = 'run_ended';
    public const string NO_FRIEND = 'no_friend';
    public const string TOO_MANY = 'too_many';
    public const string WEEK_LIMIT = 'week_limit';
    public const string CLOSED = 'closed';
    public const string BLOCKED = 'blocked';

    public const int MAX_OPPONENTS = WeeklyDuel::MAX_OPPONENTS;
    public const int MAX_PER_WEEK = WeeklyDuel::MAX_PER_WEEK;

    public function __construct(
        private WeeklyDuelRepositoryInterface $duels,
        private WeeklyDuelContextQueryInterface $context,
        private WeeklyRunFriendEntriesQueryInterface $entries,
        private FriendCircleQuery $friendCircle,
        private CommunityUserDirectoryQueryInterface $directory,
        private Notifier $notifier,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $userIds
     *
     * @return array{outcome: string, duelId: string|null}
     */
    public function challenge(string $weeklyRunId, string $creatorId, array $userIds): array
    {
        $run = $this->context->runs([$weeklyRunId])[$weeklyRunId] ?? null;
        if (null === $run) {
            return ['outcome' => self::NOT_FOUND, 'duelId' => null];
        }
        if (!$run['active']) {
            return ['outcome' => self::RUN_ENDED, 'duelId' => null];
        }

        $friends = array_flip($this->friendCircle->memberIds($creatorId));
        $opponents = array_values(array_filter(
            array_unique($userIds),
            static fn (string $id): bool => $id !== $creatorId && isset($friends[$id]),
        ));
        if ([] === $opponents) {
            return ['outcome' => self::NO_FRIEND, 'duelId' => null];
        }
        if (\count($opponents) > self::MAX_OPPONENTS) {
            return ['outcome' => self::TOO_MANY, 'duelId' => null];
        }
        // Story 43.19: the whole week counts, whatever weekly run of it the duels are on.
        if ($this->context->duelsCreatedInWeek($creatorId, $run['weekYear'], $run['weekNumber']) >= self::MAX_PER_WEEK) {
            return ['outcome' => self::WEEK_LIMIT, 'duelId' => null];
        }

        $now = $this->clock->now();
        $duel = WeeklyDuel::open($weeklyRunId, $creatorId, $now);
        $this->duels->save($duel);
        $this->duels->saveParticipant(WeeklyDuelParticipant::creator($duel, $now));
        foreach ($opponents as $userId) {
            $this->duels->saveParticipant(WeeklyDuelParticipant::challenge($duel, $userId, $now));
        }
        $this->duels->flush();

        $creatorName = $this->directory->namesFor([$creatorId])[$creatorId] ?? null;
        foreach ($opponents as $userId) {
            $this->notifier->notify($userId, self::NOTIFICATION_TYPE, [
                'fromUserId' => $creatorId,
                'challengerName' => $creatorName,
                'duelId' => $duel->getId(),
                'weeklyRunId' => $weeklyRunId,
                'gameName' => $run['gameName'],
            ]);
        }

        return ['outcome' => self::OK, 'duelId' => $duel->getId()];
    }

    public function accept(string $duelId, string $userId): string
    {
        return $this->answer($duelId, $userId, true);
    }

    public function decline(string $duelId, string $userId): string
    {
        return $this->answer($duelId, $userId, false);
    }

    /**
     * The open duels the viewer is in, on the given weekly run or on any; each with its members ranked: those who
     * accepted by their standing in the weekly run, then those not answered yet.
     *
     * @return list<array<string, mixed>>
     */
    public function forViewer(string $viewerId, ?string $weeklyRunId = null): array
    {
        $duels = array_values(array_filter(
            $this->duels->openDuelsOf($viewerId),
            static fn (WeeklyDuel $duel): bool => null === $weeklyRunId || $duel->getWeeklyRunId() === $weeklyRunId,
        ));
        if ([] === $duels) {
            return [];
        }

        $participants = $this->duels->participantsByDuel(array_map(static fn (WeeklyDuel $d): string => $d->getId(), $duels));
        $runs = $this->context->runs(array_values(array_unique(array_map(static fn (WeeklyDuel $d): string => $d->getWeeklyRunId(), $duels))));
        $this->applyBlocks($duels, $participants);

        $rows = [];
        foreach ($duels as $duel) {
            $run = $runs[$duel->getWeeklyRunId()] ?? null;
            $members = $participants[$duel->getId()] ?? [];
            $mine = null;
            foreach ($members as $member) {
                if ($member->getUserId() === $viewerId) {
                    $mine = $member;
                }
            }
            if (null === $run || !$run['active'] || null === $mine || !$mine->isActive()) {
                continue;
            }
            $rows[] = [
                'duelId' => $duel->getId(),
                'weeklyRunId' => $duel->getWeeklyRunId(),
                'gameName' => $run['gameName'],
                'isCreator' => $duel->isCreatedBy($viewerId),
                'myStatus' => $mine->getStatus(),
                'standings' => $this->standings($duel, $members, $viewerId),
            ];
        }

        return $rows;
    }

    private function answer(string $duelId, string $userId, bool $accept): string
    {
        $duel = $this->duels->find($duelId);
        $members = $duel instanceof WeeklyDuel ? ($this->duels->participantsByDuel([$duelId])[$duelId] ?? []) : [];
        $mine = null;
        foreach ($members as $member) {
            if ($member->getUserId() === $userId) {
                $mine = $member;
            }
        }
        if (!$duel instanceof WeeklyDuel || null === $mine) {
            return self::NOT_FOUND;
        }
        $run = $this->context->runs([$duel->getWeeklyRunId()])[$duel->getWeeklyRunId()] ?? null;
        if ($duel->isResolved() || null === $run || !$run['active'] || !$mine->isPending()) {
            return self::CLOSED;
        }

        $now = $this->clock->now();
        WeeklyDuelBlockRule::apply($duel, $members, $this->context->blocksAmong(WeeklyDuelBlockRule::activeUserIds($members)), $now);
        if (!$mine->isActive()) {
            $this->duels->flush();

            return self::BLOCKED;
        }

        $accept ? $mine->accept($now) : $mine->decline($now);
        $this->duels->flush();

        return self::OK;
    }

    /**
     * @param list<WeeklyDuel>                           $duels
     * @param array<string, list<WeeklyDuelParticipant>> $participants
     */
    private function applyBlocks(array $duels, array $participants): void
    {
        $everyone = [];
        foreach ($participants as $members) {
            array_push($everyone, ...WeeklyDuelBlockRule::activeUserIds($members));
        }
        $blocks = $this->context->blocksAmong(array_values(array_unique($everyone)));
        if ([] === $blocks) {
            return;
        }

        $now = $this->clock->now();
        $changed = false;
        foreach ($duels as $duel) {
            $changed = WeeklyDuelBlockRule::apply($duel, $participants[$duel->getId()] ?? [], $blocks, $now) || $changed;
        }
        if ($changed) {
            $this->duels->flush();
        }
    }

    /**
     * @param list<WeeklyDuelParticipant> $members
     *
     * @return list<array<string, mixed>>
     */
    private function standings(WeeklyDuel $duel, array $members, string $viewerId): array
    {
        $accepted = [];
        $pending = [];
        foreach ($members as $member) {
            if ($member->isAccepted()) {
                $accepted[] = $member->getUserId();
            } elseif ($member->isPending()) {
                $pending[] = $member->getUserId();
            }
        }

        $ranked = WeeklyStanding::rank($this->entries->entriesOf($duel->getWeeklyRunId(), $accepted));
        foreach ($accepted as $userId) {
            $ranked[$userId] ??= ['status' => 'none', 'completionTimeSeconds' => null];
        }
        foreach ($pending as $userId) {
            $ranked[$userId] = ['status' => 'invited', 'completionTimeSeconds' => null];
        }

        $cards = $this->directory->cards(array_keys($ranked));
        $rows = [];
        foreach ($ranked as $userId => $standing) {
            $card = $cards[$userId] ?? null;
            if (null !== $card) {
                $rows[] = [...$card, ...$standing, 'isViewer' => $userId === $viewerId, 'isCreator' => $duel->isCreatedBy($userId)];
            }
        }

        return $rows;
    }
}
