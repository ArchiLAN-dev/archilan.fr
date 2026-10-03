<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Query;

use App\Shared\Infrastructure\Dbal\DbalSlotCheckSource;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use App\Wallet\Application\Query\WeeklyQuestsQueryInterface;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Enum\WeeklyQuest;
use App\Wallet\Domain\ValueObject\QuestWeek;
use Doctrine\DBAL\Connection;

/**
 * Reads the quests of the week (story 41.6) from what was actually played: the session feed (checks, through
 * the slot's players), slot goals and weekly attempts. Like the statistics, it reads those tables in plain SQL
 * without making Wallet depend on Sessions or WeeklyRuns.
 */
final readonly class DbalWeeklyQuestsQuery implements WeeklyQuestsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function completers(QuestWeek $week): array
    {
        $bounds = ['weekStart' => $week->start->format(\DATE_ATOM), 'weekEnd' => $week->end->format(\DATE_ATOM)];
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $playerColumn = 'sp.'.DbalSlotPlayerSource::USER_COLUMN;

        $goals = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT {$playerColumn}
               FROM session_slot slot
               JOIN {$players} sp ON sp.".DbalSlotPlayerSource::SLOT_COLUMN.' = slot.id
              WHERE slot.goal_reached_at >= :weekStart AND slot.goal_reached_at < :weekEnd
             UNION
             SELECT DISTINCT user_id FROM weekly_entries
              WHERE goal_reached_at >= :weekStart AND goal_reached_at < :weekEnd',
            $bounds,
        );

        $checks = DbalSlotCheckSource::from();
        $player = DbalSlotCheckSource::player();
        $newPartners = $this->connection->fetchFirstColumn(
            "WITH this_week AS (
                 SELECT DISTINCT f.session_id, {$player} AS uid {$checks} AND f.occurred_at >= :weekStart AND f.occurred_at < :weekEnd
             ), before AS (
                 SELECT DISTINCT f.session_id, {$player} AS uid {$checks} AND f.occurred_at < :weekStart
             )
             SELECT DISTINCT a.uid
               FROM this_week a
               JOIN this_week b ON b.session_id = a.session_id AND b.uid <> a.uid
              WHERE NOT EXISTS (
                    SELECT 1 FROM before x JOIN before y ON y.session_id = x.session_id
                     WHERE x.uid = a.uid AND y.uid = b.uid
              )",
            DbalSlotCheckSource::params() + $bounds,
            DbalSlotCheckSource::types(),
        );

        $weeklies = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT user_id FROM weekly_entries
              WHERE launched_at >= :weekStart AND launched_at < :weekEnd
                AND (COALESCE(checks_total, 0) > 0 OR goal_reached_at IS NOT NULL)',
            $bounds,
        );

        return [
            WeeklyQuest::ReachAGoal->value => $this->ids($goals),
            WeeklyQuest::PlayWithSomeoneNew->value => $this->ids($newPartners),
            WeeklyQuest::PlayAWeekly->value => $this->ids($weeklies),
        ];
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

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private function ids(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (is_string($value) && '' !== $value) {
                $ids[] = $value;
            }
        }

        return $ids;
    }
}
