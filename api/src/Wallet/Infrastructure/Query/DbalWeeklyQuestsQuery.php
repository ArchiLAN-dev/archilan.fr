<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Query;

use App\Shared\Infrastructure\Dbal\DbalFoundItemSource;
use App\Shared\Infrastructure\Dbal\DbalSlotCheckSource;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use App\Wallet\Application\Query\WeeklyQuestsQueryInterface;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;
use Doctrine\DBAL\Connection;

/**
 * Counts what each member played over the week (stories 41.6, 41.15) from the session feed (checks, through the
 * slot's players), slot goals and weekly attempts. Like the statistics, it reads those tables in plain SQL without
 * making Wallet depend on Sessions or WeeklyRuns. Weekly attempts have no session feed: their checks count through
 * the attempt's own total.
 */
final readonly class DbalWeeklyQuestsQuery implements WeeklyQuestsQueryInterface
{
    /** Story 41.65: a recap's superlatives pay a quest from three members on; the achievement takes two. */
    public const int MIN_SUPERLATIVE_PLAYERS = 3;

    public function __construct(private Connection $connection)
    {
    }

    public function counts(QuestWeek $week, array $objectives, ?string $userId = null): array
    {
        $counts = [];
        foreach ($objectives as $objective) {
            // Story 41.18: an objective on a game or an event counts only that game's or event's sessions.
            $scope = $this->scope($objective);
            $rows = match ($objective->metric) {
                QuestMetric::Goals => $this->goals($week, $scope),
                QuestMetric::Checks => $this->checks($week, $scope),
                QuestMetric::Weeklies => $this->weeklies($week),
                QuestMetric::NewPartners => $this->partners($week, 'new'),
                QuestMetric::ReturningPartners => $this->partners($week, 'returning'),
                QuestMetric::FriendsPlayedWith => $this->partners($week, 'friends'),
                QuestMetric::Sessions => $this->perCheck($week, 'COUNT(DISTINCT f.session_id)', $scope),
                QuestMetric::DistinctGames => $this->perCheck($week, 'COUNT(DISTINCT slot.game_id)', null),
                QuestMetric::ItemsSent => $this->itemsTraded($week, $scope, true),
                QuestMetric::ItemsReceived => $this->itemsTraded($week, $scope, false),
                QuestMetric::PlayMinutes => $this->playMinutes($week, $scope),
                QuestMetric::NewGames => $this->newGames($week),
                QuestMetric::DuelsWon => $this->duelsWon($week),
                QuestMetric::Superlatives => $this->superlatives($week, $scope),
                QuestMetric::BountiesWon => $this->bountiesWon($week),
                QuestMetric::BlindTestSources => $this->blindTestSources($week),
            };
            $counts[$objective->key()] = $this->byMember($rows, $userId);
        }

        return $counts;
    }

    public function scopeOptions(): array
    {
        $games = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT DISTINCT g.id, g.name FROM session_slot slot JOIN game g ON g.id = slot.game_id ORDER BY g.name',
        ) as $row) {
            if (is_string($row['id'] ?? null) && is_string($row['name'] ?? null)) {
                $games[] = ['id' => $row['id'], 'name' => $row['name']];
            }
        }

        $events = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT id, title FROM event WHERE status <> 'draft' ORDER BY starts_at DESC",
        ) as $row) {
            if (is_string($row['id'] ?? null) && is_string($row['title'] ?? null)) {
                $events[] = ['id' => $row['id'], 'title' => $row['title']];
            }
        }

        return ['games' => $games, 'events' => $events];
    }

    public function rewardedQuests(string $userId, QuestWeek $week): array
    {
        $keys = $this->connection->fetchFirstColumn(
            'SELECT unique_key FROM pelle_movement WHERE user_id = :userId AND reason = :reason AND unique_key LIKE :prefix',
            ['userId' => $userId, 'reason' => PelleReason::QuestReward->value, 'prefix' => sprintf('quest:%s:%%', $week->key)],
        );

        $quests = [];
        foreach ($keys as $key) {
            // quest:{week}:{quest}:{user}
            $parts = explode(':', is_string($key) ? $key : '');
            if (4 === \count($parts)) {
                $quests[] = $parts[2];
            }
        }

        return $quests;
    }

    public function activeMembers(\DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $checks = DbalSlotCheckSource::from();
        $player = DbalSlotCheckSource::player();
        $weekly = self::weeklyChecks();

        return $this->ids($this->connection->fetchFirstColumn(
            "SELECT DISTINCT {$player} {$checks} AND f.occurred_at >= :weekStart AND f.occurred_at < :weekEnd
             UNION
             SELECT DISTINCT wc.uid FROM ({$weekly}) wc
              WHERE wc.n > 0 OR (wc.goal_reached_at >= :weekStart AND wc.goal_reached_at < :weekEnd)",
            DbalSlotCheckSource::params() + $this->period($since, $until),
            DbalSlotCheckSource::types(),
        ));
    }

    public function earnedBy(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT unique_key, amount FROM pelle_movement WHERE user_id = :userId AND reason = :reason AND unique_key IS NOT NULL',
            ['userId' => $userId, 'reason' => PelleReason::QuestReward->value],
        );

        $weeks = [];
        foreach ($rows as $row) {
            $key = $row['unique_key'] ?? null;
            $amount = filter_var($row['amount'] ?? null, \FILTER_VALIDATE_INT);
            if (!is_string($key) || false === $amount) {
                continue;
            }
            // quest:{week}:{quest}:{member} or quest-chest:{week}:{member}
            $parts = explode(':', $key);
            $week = $parts[1] ?? '';
            $weeks[$week] ??= ['quests' => [], 'pelles' => 0, 'chest' => false];
            $weeks[$week]['pelles'] += $amount;
            if ('quest' === $parts[0] && 4 === \count($parts)) {
                $weeks[$week]['quests'][] = $parts[2];
            } elseif ('quest-chest' === $parts[0]) {
                $weeks[$week]['chest'] = true;
            }
        }

        return $weeks;
    }

    public function chestPaid(string $userId, QuestWeek $week): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM pelle_movement WHERE user_id = :userId AND reason = :reason AND unique_key = :key',
            ['userId' => $userId, 'reason' => PelleReason::QuestReward->value, 'key' => sprintf('quest-chest:%s:%s', $week->key, $userId)],
        );
    }

    public function payments(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT unique_key, amount FROM pelle_movement WHERE reason = :reason AND unique_key IS NOT NULL',
            ['reason' => PelleReason::QuestReward->value],
        );

        $weeks = [];
        foreach ($rows as $row) {
            $key = $row['unique_key'] ?? null;
            $amount = filter_var($row['amount'] ?? null, \FILTER_VALIDATE_INT);
            if (!is_string($key) || false === $amount) {
                continue;
            }
            // quest:{week}:{quest}:{member} or quest-chest:{week}:{member}
            $parts = explode(':', $key);
            $week = $parts[1] ?? '';
            $weeks[$week] ??= ['quests' => [], 'chests' => 0, 'chestPelles' => 0];
            if ('quest' === $parts[0] && 4 === \count($parts)) {
                $quest = $weeks[$week]['quests'][$parts[2]] ?? ['members' => 0, 'pelles' => 0];
                $weeks[$week]['quests'][$parts[2]] = ['members' => $quest['members'] + 1, 'pelles' => $quest['pelles'] + $amount];
            } elseif ('quest-chest' === $parts[0] && 3 === \count($parts)) {
                ++$weeks[$week]['chests'];
                $weeks[$week]['chestPelles'] += $amount;
            }
        }

        return $weeks;
    }

    /**
     * @param array{sql: string, id: string}|null $scope
     *
     * @return list<array<string, mixed>>
     */
    private function goals(QuestWeek $week, ?array $scope): array
    {
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $playerColumn = 'sp.'.DbalSlotPlayerSource::USER_COLUMN;
        // A weekly attempt belongs to no game or event the admin can aim at: a scoped objective counts sessions only.
        $weeklies = null === $scope ? 'UNION ALL
                 SELECT id AS ref, user_id AS uid FROM weekly_entries
                  WHERE goal_reached_at >= :weekStart AND goal_reached_at < :weekEnd' : '';

        return $this->connection->fetchAllAssociative(
            "SELECT uid, COUNT(*) AS n FROM (
                 SELECT DISTINCT slot.id AS ref, {$playerColumn} AS uid
                   FROM session_slot slot
                   JOIN {$players} sp ON sp.".DbalSlotPlayerSource::SLOT_COLUMN.' = slot.id
                  WHERE slot.goal_reached_at >= :weekStart AND slot.goal_reached_at < :weekEnd '.($scope['sql'] ?? '').'
                 '.$weeklies.'
             ) reached GROUP BY uid',
            $this->bounds($week) + (null === $scope ? [] : ['scopeId' => $scope['id']]),
        );
    }

    /**
     * @param array{sql: string, id: string}|null $scope
     *
     * @return list<array<string, mixed>>
     */
    private function checks(QuestWeek $week, ?array $scope): array
    {
        $checks = DbalSlotCheckSource::from();
        $player = DbalSlotCheckSource::player();
        // Story 41.19: a weekly attempt's checks of the week, read in its session feed.
        $weeklies = null === $scope ? 'UNION ALL
                 SELECT wc.uid, SUM(wc.n) AS n FROM ('.self::weeklyChecks().') wc GROUP BY wc.uid' : '';

        return $this->connection->fetchAllAssociative(
            "SELECT uid, SUM(n) AS n FROM (
                 SELECT {$player} AS uid, COUNT(*) AS n {$checks} AND f.occurred_at >= :weekStart AND f.occurred_at < :weekEnd ".($scope['sql'] ?? '')." GROUP BY {$player}
                 {$weeklies}
             ) made GROUP BY uid",
            DbalSlotCheckSource::params() + $this->bounds($week) + ['attemptsFrom' => $this->attemptsFrom($week->start)] + (null === $scope ? [] : ['scopeId' => $scope['id']]),
            DbalSlotCheckSource::types(),
        );
    }

    /**
     * Story 41.33: a weekly attempt counts once finished, in the week its goal is reached - like a goal, so an
     * attempt launched the week before counts in the week it ends, and never twice.
     *
     * @return list<array<string, mixed>>
     */
    private function weeklies(QuestWeek $week): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT user_id AS uid, COUNT(*) AS n FROM weekly_entries
              WHERE goal_reached_at >= :weekStart AND goal_reached_at < :weekEnd
              GROUP BY user_id',
            $this->bounds($week),
        );
    }

    /**
     * Story 41.19: each weekly attempt with its checks between :weekStart and :weekEnd, for its player. A weekly
     * session writes its feed like any session, but has no session slot to tie a check to a member: the attempt
     * does (`external_session_id`, `user_id`). An attempt with no feed on record falls back to its
     * `checks_total` (only known once its goal is reached), counted in the week it was launched. Attempts launched
     * up to two weeks before still count the checks they make in the period.
     */
    private static function weeklyChecks(): string
    {
        return 'SELECT we.id AS entry, we.user_id AS uid, we.launched_at, we.goal_reached_at,
                       CASE
                           WHEN EXISTS (SELECT 1 FROM session_feed_event fe WHERE fe.session_id = we.external_session_id)
                           THEN (SELECT COUNT(*) FROM session_feed_event wf
                                  WHERE wf.session_id = we.external_session_id AND wf.type IN (:checkTypes)
                                    AND wf.occurred_at >= :weekStart AND wf.occurred_at < :weekEnd)
                           WHEN we.launched_at >= :weekStart AND we.launched_at < :weekEnd THEN COALESCE(we.checks_total, 0)
                           ELSE 0
                       END AS n
                  FROM weekly_entries we
                 WHERE we.launched_at IS NOT NULL AND we.launched_at >= :attemptsFrom AND we.launched_at < :weekEnd';
    }

    private function attemptsFrom(\DateTimeImmutable $start): string
    {
        return $start->modify('-14 days')->format(\DATE_ATOM);
    }

    /** @return array{weekStart: string, weekEnd: string, attemptsFrom: string} */
    private function period(\DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        return ['weekStart' => $since->format(\DATE_ATOM), 'weekEnd' => $until->format(\DATE_ATOM), 'attemptsFrom' => $this->attemptsFrom($since)];
    }

    /**
     * The members each member played with in the week - a session where both made a check: those never met before
     * (`new`), those already met (`returning`, story 41.65), or their accepted friends (`friends`, story 41.65; the
     * friendship as it stands, a payment already made stays).
     *
     * @param 'new'|'returning'|'friends' $who
     *
     * @return list<array<string, mixed>>
     */
    private function partners(QuestWeek $week, string $who): array
    {
        $checks = DbalSlotCheckSource::from();
        $player = DbalSlotCheckSource::player();
        $met = 'SELECT 1 FROM before x JOIN before y ON y.session_id = x.session_id WHERE x.uid = a.uid AND y.uid = b.uid';
        $condition = match ($who) {
            'new' => "NOT EXISTS ({$met})",
            'returning' => "EXISTS ({$met})",
            'friends' => "EXISTS (SELECT 1 FROM community_friendship fr WHERE fr.status = 'accepted'
                            AND ((fr.requester_id = a.uid AND fr.addressee_id = b.uid) OR (fr.requester_id = b.uid AND fr.addressee_id = a.uid)))",
        };

        return $this->connection->fetchAllAssociative(
            "WITH this_week AS (
                 SELECT DISTINCT f.session_id, {$player} AS uid {$checks} AND f.occurred_at >= :weekStart AND f.occurred_at < :weekEnd
             ), before AS (
                 SELECT DISTINCT f.session_id, {$player} AS uid {$checks} AND f.occurred_at < :weekStart
             )
             SELECT a.uid, COUNT(DISTINCT b.uid) AS n
               FROM this_week a
               JOIN this_week b ON b.session_id = a.session_id AND b.uid <> a.uid
              WHERE {$condition}
              GROUP BY a.uid",
            DbalSlotCheckSource::params() + $this->bounds($week),
            DbalSlotCheckSource::types(),
        );
    }

    /**
     * Story 41.65: the weekly duels a member won, settled in the week, against at least one accepted opponent who
     * played the weekly - an opponent who accepts and never plays hands nobody a quest.
     *
     * @return list<array<string, mixed>>
     */
    private function duelsWon(QuestWeek $week): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT d.winner_id AS uid, COUNT(*) AS n
               FROM weekly_duel d
              WHERE d.winner_id IS NOT NULL AND d.resolved_at >= :weekStart AND d.resolved_at < :weekEnd
                AND EXISTS (
                    SELECT 1 FROM weekly_duel_participant p
                      JOIN weekly_entries we ON we.weekly_run_id = d.weekly_run_id AND we.user_id = p.user_id
                     WHERE p.duel_id = d.id AND p.user_id <> d.winner_id AND p.status = 'accepted'
                       AND (COALESCE(we.checks_total, 0) > 0
                            OR EXISTS (SELECT 1 FROM session_feed_event wf WHERE wf.session_id = we.external_session_id AND wf.type IN (:checkTypes)))
                )
              GROUP BY d.winner_id",
            DbalSlotCheckSource::params() + $this->bounds($week),
            DbalSlotCheckSource::types(),
        );
    }

    /**
     * Story 41.65: the recap superlatives a member won, in the week the recap was built (a recap built late still
     * counts, in the week it appears). Same sessions as the achievement - a personal run, or a public event with a
     * submitted reservation - but with at least MIN_SUPERLATIVE_PLAYERS members: with two, a second account takes
     * all. A member counts once per session and superlative.
     *
     * @param array{sql: string, id: string}|null $scope
     *
     * @return list<array<string, mixed>>
     */
    private function superlatives(QuestWeek $week, ?array $scope): array
    {
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $slotColumn = DbalSlotPlayerSource::SLOT_COLUMN;
        $userColumn = DbalSlotPlayerSource::USER_COLUMN;

        $rows = $this->connection->fetchAllAssociative(
            "SELECT sr.session_id, sr.superlatives, COALESCE(slot.slot_id, slot.id) AS slot_ref, sp.{$userColumn} AS uid
               FROM session_recap sr
               JOIN session s ON s.id = sr.session_id
               JOIN session_slot slot ON slot.session_id = s.id
               JOIN {$players} sp ON sp.{$slotColumn} = slot.id
               LEFT JOIN event e ON e.id = s.event_id
               LEFT JOIN registration r ON r.id = slot.registration_id AND r.event_id = s.event_id
               LEFT JOIN run ON run.id = s.event_id
              WHERE sr.generated_at >= :weekStart AND sr.generated_at < :weekEnd
                AND s.status = 'finished'
                AND (run.id IS NOT NULL OR (e.is_public = TRUE AND r.status = 'reserved' AND r.submitted_at IS NOT NULL))
                AND (SELECT COUNT(DISTINCT pc.{$userColumn}) FROM session_slot ps JOIN {$players} pc ON pc.{$slotColumn} = ps.id
                      WHERE ps.session_id = s.id) >= :minPlayers
                ".($scope['sql'] ?? ''),
            $this->bounds($week) + ['minPlayers' => self::MIN_SUPERLATIVE_PLAYERS] + (null === $scope ? [] : ['scopeId' => $scope['id']]),
        );

        /** @var array<string, array<string, true>> $won uid => "session|key" */
        $won = [];
        foreach ($rows as $row) {
            $sessionId = $row['session_id'] ?? null;
            $slotRef = $row['slot_ref'] ?? null;
            $uid = $row['uid'] ?? null;
            $json = $row['superlatives'] ?? null;
            $superlatives = is_string($json) ? json_decode($json, true) : null;
            if (!is_string($sessionId) || !is_string($slotRef) || !is_string($uid) || !is_array($superlatives)) {
                continue;
            }
            foreach ($superlatives as $superlative) {
                if (is_array($superlative) && is_string($superlative['key'] ?? null) && ($superlative['slotId'] ?? null) === $slotRef) {
                    $won[$uid][$sessionId.'|'.$superlative['key']] = true;
                }
            }
        }

        $rows = [];
        foreach ($won as $uid => $keys) {
            $rows[] = ['uid' => $uid, 'n' => \count($keys)];
        }

        return $rows;
    }

    /**
     * Story 41.65: the bounties a member won in the week - in gold only (a private run's bronze bounty costs
     * nothing), and posted by somebody else.
     *
     * @return list<array<string, mixed>>
     */
    private function bountiesWon(QuestWeek $week): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT winner_id AS uid, COUNT(*) AS n
               FROM item_bounty
              WHERE status = 'paid' AND run_id IS NULL AND winner_id IS NOT NULL AND winner_id <> poster_id
                AND settled_at >= :weekStart AND settled_at < :weekEnd
              GROUP BY winner_id",
            $this->bounds($week),
        );
    }

    /**
     * Story 41.65: the sources a member found in an event's blind test - one ledger credit each
     * (`blindtest:{event}:{track}:{source}:{user}`).
     *
     * @return list<array<string, mixed>>
     */
    private function blindTestSources(QuestWeek $week): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT user_id AS uid, COUNT(*) AS n
               FROM pelle_movement
              WHERE reason = :reason AND unique_key LIKE :prefix AND created_at >= :weekStart AND created_at < :weekEnd
              GROUP BY user_id',
            ['reason' => PelleReason::BlindTest->value, 'prefix' => 'blindtest:%'] + $this->bounds($week),
        );
    }

    /**
     * @param array{sql: string, id: string}|null $scope
     *
     * @return list<array<string, mixed>>
     */
    private function perCheck(QuestWeek $week, string $aggregate, ?array $scope): array
    {
        $checks = DbalSlotCheckSource::from();
        $player = DbalSlotCheckSource::player();

        return $this->connection->fetchAllAssociative(
            "SELECT {$player} AS uid, {$aggregate} AS n {$checks} AND f.occurred_at >= :weekStart AND f.occurred_at < :weekEnd ".($scope['sql'] ?? '')." GROUP BY {$player}",
            DbalSlotCheckSource::params() + $this->bounds($week) + (null === $scope ? [] : ['scopeId' => $scope['id']]),
            DbalSlotCheckSource::types(),
        );
    }

    /**
     * Story 41.64: the items a member found for another member's slot (`$sent`), or received from one, in the week.
     * The member's slot is `slot`, so a game or event target narrows it: the sender's game for items sent, the
     * receiver's for items received. An item between two slots of the same member, or with a slot no member
     * plays, does not count; neither does an item of a release or a collect.
     *
     * @param array{sql: string, id: string}|null $scope
     *
     * @return list<array<string, mixed>>
     */
    private function itemsTraded(QuestWeek $week, ?array $scope, bool $sent): array
    {
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $slotColumn = DbalSlotPlayerSource::SLOT_COLUMN;
        $userColumn = DbalSlotPlayerSource::USER_COLUMN;
        $feed = DbalFoundItemSource::feed('AND fe.occurred_at >= :weekStart AND fe.occurred_at < :weekEnd');
        [$mine, $theirs] = $sent ? ['sender_name', 'receiver_name'] : ['receiver_name', 'sender_name'];
        $found = implode(' AND ', array_map(
            static fn (string $rule): string => '('.$rule.')',
            $sent ? DbalFoundItemSource::found('f', 'slot', 'other') : DbalFoundItemSource::found('f', 'other', 'slot'),
        ));

        return $this->connection->fetchAllAssociative(
            "SELECT mp.{$userColumn} AS uid, COUNT(DISTINCT f.id) AS n
               FROM {$feed} f
               JOIN session_slot slot ON slot.session_id = f.session_id AND slot.slot_name = f.{$mine}
               JOIN session_slot other ON other.session_id = f.session_id AND other.slot_name = f.{$theirs}
               JOIN {$players} mp ON mp.{$slotColumn} = slot.id
              WHERE other.id <> slot.id
                AND NOT EXISTS (SELECT 1 FROM {$players} op WHERE op.{$slotColumn} = other.id AND op.{$userColumn} = mp.{$userColumn})
                AND EXISTS (SELECT 1 FROM {$players} op WHERE op.{$slotColumn} = other.id AND op.{$userColumn} IS NOT NULL AND op.{$userColumn} <> mp.{$userColumn})
                AND {$found} ".($scope['sql'] ?? '')."
              GROUP BY mp.{$userColumn}",
            DbalFoundItemSource::params() + $this->bounds($week) + (null === $scope ? [] : ['scopeId' => $scope['id']]),
        );
    }

    /**
     * Story 41.64: the minutes a member was connected in the week, a span across two weeks counting its share in
     * each. Only the slots that made a check in the week count, so a client left open earns nothing; a slot played
     * by several members counts its time for each of them, like the achievement. Weekly attempts have no span.
     *
     * @param array{sql: string, id: string}|null $scope
     *
     * @return list<array<string, mixed>>
     */
    private function playMinutes(QuestWeek $week, ?array $scope): array
    {
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $userColumn = 'sp.'.DbalSlotPlayerSource::USER_COLUMN;

        return $this->connection->fetchAllAssociative(
            "SELECT spans.uid, CAST(FLOOR(SUM(spans.seconds) / 60) AS integer) AS n FROM (
                 SELECT DISTINCT p.id, {$userColumn} AS uid,
                        EXTRACT(EPOCH FROM (LEAST(p.last_seen_at, CAST(:weekEnd AS timestamptz)) - GREATEST(p.started_at, CAST(:weekStart AS timestamptz)))) AS seconds
                   FROM session_slot_play_span p
                   JOIN session_slot slot ON slot.session_id = p.session_id AND slot.slot_name = p.slot_name
                   JOIN {$players} sp ON sp.".DbalSlotPlayerSource::SLOT_COLUMN.' = slot.id
                  WHERE p.started_at < :weekEnd AND p.last_seen_at > :weekStart
                    AND EXISTS (SELECT 1 FROM session_feed_event c
                                 WHERE c.session_id = slot.session_id AND c.sender_name = slot.slot_name AND c.type IN (:checkTypes)
                                   AND c.occurred_at >= :weekStart AND c.occurred_at < :weekEnd)
                    '.($scope['sql'] ?? '').'
             ) spans GROUP BY spans.uid',
            DbalSlotCheckSource::params() + $this->bounds($week) + (null === $scope ? [] : ['scopeId' => $scope['id']]),
            DbalSlotCheckSource::types(),
        );
    }

    /**
     * Story 41.64: the games a member made the first check of their life on, in the week - in a session, or in a
     * weekly attempt (its game through its run's template). A weekly attempt with no feed on record counts from its
     * launch when it made checks.
     *
     * @return list<array<string, mixed>>
     */
    private function newGames(QuestWeek $week): array
    {
        $checks = DbalSlotCheckSource::from();
        $player = DbalSlotCheckSource::player();

        return $this->connection->fetchAllAssociative(
            "WITH played AS (
                 SELECT {$player} AS uid, slot.game_id AS game, MIN(f.occurred_at) AS first_at {$checks} AND slot.game_id IS NOT NULL
                  GROUP BY {$player}, slot.game_id
                 UNION ALL
                 SELECT we.user_id AS uid, wt.game_id AS game,
                        COALESCE(
                            (SELECT MIN(wf.occurred_at) FROM session_feed_event wf WHERE wf.session_id = we.external_session_id AND wf.type IN (:checkTypes)),
                            CASE WHEN we.checks_total > 0 THEN we.launched_at END
                        ) AS first_at
                   FROM weekly_entries we
                   JOIN weekly_runs wr ON wr.id = we.weekly_run_id
                   JOIN weekly_templates wt ON wt.id = wr.template_id
                  WHERE we.launched_at IS NOT NULL
             ), firsts AS (
                 SELECT uid, game, MIN(first_at) AS first_at FROM played WHERE first_at IS NOT NULL GROUP BY uid, game
             )
             SELECT uid, COUNT(*) AS n FROM firsts WHERE first_at >= :weekStart AND first_at < :weekEnd GROUP BY uid",
            DbalSlotCheckSource::params() + $this->bounds($week),
            DbalSlotCheckSource::types(),
        );
    }

    /**
     * Story 41.18: the condition on the slot (`slot`) that narrows a count to the objective's game or event.
     *
     * @return array{sql: string, id: string}|null
     */
    private function scope(QuestObjective $objective): ?array
    {
        return match ($objective->scope) {
            QuestObjective::SCOPE_GAME => ['sql' => 'AND slot.game_id = :scopeId', 'id' => (string) $objective->scopeId],
            QuestObjective::SCOPE_EVENT => ['sql' => 'AND slot.session_id IN (SELECT ev.id FROM session ev WHERE ev.event_id = :scopeId)', 'id' => (string) $objective->scopeId],
            default => null,
        };
    }

    /** @return array{weekStart: string, weekEnd: string} */
    private function bounds(QuestWeek $week): array
    {
        return ['weekStart' => $week->start->format(\DATE_ATOM), 'weekEnd' => $week->end->format(\DATE_ATOM)];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int>
     */
    private function byMember(array $rows, ?string $userId): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $uid = $row['uid'] ?? null;
            $count = filter_var($row['n'] ?? null, \FILTER_VALIDATE_INT);
            if (!is_string($uid) || '' === $uid || false === $count || $count <= 0 || (null !== $userId && $uid !== $userId)) {
                continue;
            }
            $counts[$uid] = $count;
        }

        return $counts;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private function ids(array $values): array
    {
        return array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && '' !== $value));
    }
}
