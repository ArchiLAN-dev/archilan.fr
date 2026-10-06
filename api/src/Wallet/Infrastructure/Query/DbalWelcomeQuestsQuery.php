<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Query;

use App\Shared\Infrastructure\Dbal\DbalSlotCheckSource;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use App\Wallet\Application\Query\WelcomeQuestsQueryInterface;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Enum\WelcomeStep;
use Doctrine\DBAL\Connection;

/**
 * Story 41.25: who did each first step, from the account (Discord), the session feed, slot goals and weekly
 * attempts - read in plain SQL like the weekly quests, without making Wallet depend on Sessions or WeeklyRuns.
 */
final readonly class DbalWelcomeQuestsQuery implements WelcomeQuestsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function unpaid(WelcomeStep $step, ?\DateTimeImmutable $since): array
    {
        $params = ['reason' => PelleReason::WelcomeReward->value, 'prefix' => sprintf('welcome:%s:', $step->value)];
        $joined = '';
        if (null !== $since) {
            $joined = 'AND u.created_at >= :since';
            $params['since'] = $since->format(\DATE_ATOM);
        }

        return $this->ids($this->connection->fetchFirstColumn(
            'SELECT d.uid FROM ('.self::done($step).') d
               JOIN "user" u ON u.id = d.uid
              WHERE NOT EXISTS (
                    SELECT 1 FROM pelle_movement pm
                     WHERE pm.reason = :reason AND pm.unique_key = CONCAT(CAST(:prefix AS VARCHAR), d.uid)
              ) '.$joined,
            DbalSlotCheckSource::params() + $params,
            DbalSlotCheckSource::types(),
        ));
    }

    public function doneBy(string $userId): array
    {
        $done = [];
        foreach (WelcomeStep::cases() as $step) {
            $found = $this->connection->fetchOne(
                'SELECT 1 FROM ('.self::done($step).') d WHERE d.uid = :userId LIMIT 1',
                DbalSlotCheckSource::params() + ['userId' => $userId],
                DbalSlotCheckSource::types(),
            );
            if (false !== $found) {
                $done[] = $step;
            }
        }

        return $done;
    }

    public function paidTo(string $userId): array
    {
        $keys = $this->connection->fetchFirstColumn(
            'SELECT unique_key FROM pelle_movement WHERE user_id = :userId AND reason = :reason AND unique_key IS NOT NULL',
            ['userId' => $userId, 'reason' => PelleReason::WelcomeReward->value],
        );

        $paid = [];
        foreach ($keys as $key) {
            // welcome:{step}:{member}
            $step = WelcomeStep::tryFrom(explode(':', is_string($key) ? $key : '')[1] ?? '');
            if (null !== $step) {
                $paid[] = $step;
            }
        }

        return $paid;
    }

    public function joinedAt(string $userId): ?\DateTimeImmutable
    {
        $createdAt = $this->connection->fetchOne('SELECT created_at FROM "user" WHERE id = :userId', ['userId' => $userId]);
        if (!is_string($createdAt)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($createdAt);
        } catch (\Exception) {
            return null;
        }
    }

    /** The members (`uid`) who did the step. */
    private static function done(WelcomeStep $step): string
    {
        $checks = DbalSlotCheckSource::from();
        $player = DbalSlotCheckSource::player();
        $weeklyCheck = 'EXISTS (SELECT 1 FROM session_feed_event wf WHERE wf.session_id = we.external_session_id AND wf.type IN (:checkTypes))';
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');

        return match ($step) {
            WelcomeStep::Discord => 'SELECT id AS uid FROM "user" WHERE discord_id IS NOT NULL',
            WelcomeStep::FirstCheck => "SELECT {$player} AS uid {$checks}
                 UNION
                 SELECT we.user_id AS uid FROM weekly_entries we
                  WHERE we.goal_reached_at IS NOT NULL OR COALESCE(we.checks_total, 0) > 0 OR {$weeklyCheck}",
            WelcomeStep::FirstWeekly => "SELECT DISTINCT we.user_id AS uid FROM weekly_entries we
                  WHERE we.launched_at IS NOT NULL
                    AND (we.goal_reached_at IS NOT NULL OR COALESCE(we.checks_total, 0) > 0 OR {$weeklyCheck})",
            WelcomeStep::FirstPartner => "SELECT DISTINCT a.uid FROM (SELECT DISTINCT f.session_id, {$player} AS uid {$checks}) a
                   JOIN (SELECT DISTINCT f.session_id, {$player} AS uid {$checks}) b ON b.session_id = a.session_id AND b.uid <> a.uid",
            WelcomeStep::FirstGoal => 'SELECT sp.'.DbalSlotPlayerSource::USER_COLUMN." AS uid
                   FROM session_slot slot
                   JOIN {$players} sp ON sp.".DbalSlotPlayerSource::SLOT_COLUMN.' = slot.id
                  WHERE slot.goal_reached_at IS NOT NULL
                 UNION
                 SELECT user_id AS uid FROM weekly_entries WHERE goal_reached_at IS NOT NULL',
        };
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
